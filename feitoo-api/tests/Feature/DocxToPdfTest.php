<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingFileError;
use App\Jobs\ProcessDocxToPdfJob;
use App\Models\Processing;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Documents\ConversionProcessRunner;
use App\Services\Documents\DocxProcessingService;
use App\Services\Documents\DocxToPdfConverter;
use App\Services\Documents\LibreOfficeDocxToPdfConverter;
use App\Services\ProcessingService;
use App\Support\Documents\DocumentException;
use App\Support\Documents\DocxValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DocxToPdfTest extends TestCase
{
    use RefreshDatabase;

    private array $workspaces = [];

    public static function pdf(): string
    {
        // Structurally valid, synthetic one-page PDF, no document text.
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach (['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] >>'] as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create(), 'web');
        $fake = \Mockery::mock(DocxToPdfConverter::class);
        $fake->shouldReceive('convert')->andReturnUsing(function (string $input, string $workspace): string {
            $this->workspaces[] = $workspace;
            mkdir($workspace.'/output');
            file_put_contents($workspace.'/output/input.pdf', self::pdf());

            return $workspace.'/output/input.pdf';
        });
        $this->app->instance(DocxToPdfConverter::class, $fake);
    }

    private function upload(string $name = 'documento.docx', ?string $contents = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents ?? file_get_contents(base_path('tests/Fixtures/Documents/simple.docx')));
    }

    private function batch(array $uploads, bool $start = true): Processing
    {
        $id = $this->postJson('/api/processings', ['tool' => 'docx_to_pdf'])->assertCreated()->json('data.id');
        $this->post('/api/processings/'.$id.'/files', ['files' => $uploads], ['Accept' => 'application/json'])->assertOk();
        if ($start) {
            $this->postJson('/api/processings/'.$id.'/start')->assertStatus(202);
        }

        return Processing::where('uuid', $id)->firstOrFail();
    }

    private function runBatch(Processing $batch): void
    {
        foreach ($batch->files()->where('kind', 'input')->pluck('uuid') as $id) {
            (new ProcessDocxToPdfJob($batch->uuid, $id))->handle(app(DocxProcessingService::class));
        }
    }

    private function archive(array $changes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test-');
        copy(base_path('tests/Fixtures/Documents/simple.docx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        foreach ($changes as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_valid_fixtures_and_malformed_archives_are_validated_without_extraction(): void
    {
        foreach (glob(base_path('tests/Fixtures/Documents/*.docx')) as $path) {
            app(DocxValidator::class)->validate($path);
        }
        foreach ([
            'fake' => 'not a docx', 'corrupt' => substr(file_get_contents(base_path('tests/Fixtures/Documents/simple.docx')), 0, 100),
            'traversal' => $this->archive(['../escape' => 'x']),
            'absolute' => $this->archive(['/escape' => 'x']),
            'windows' => $this->archive(['C:\\escape' => 'x']),
            'macro' => $this->archive(['word/vbaProject.bin' => 'x']),
            'embedded' => $this->archive(['word/embeddings/object.bin' => 'x']),
            'external' => $this->archive(['word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="a" Type="image" Target="file:///etc/passwd" TargetMode="External"/></Relationships>']),
            'xxe' => $this->archive(['word/document.xml' => '<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><x>&a;</x>']),
            'renamed_zip' => $this->archive(['[Content_Types].xml' => '<Types/>']),
            'bomb' => $this->archive(['word/bomb.txt' => str_repeat('a', 200000)]),
        ] as $case => $bytes) {
            $file = $this->upload($case.'.docx', $bytes);
            try {
                app(DocxValidator::class)->validate($file->getRealPath());
                $this->fail($case.' accepted');
            } catch (DocumentException $exception) {
                $this->assertStringStartsWith('docx_', $exception->error->value);
            }
        }
    }

    public function test_size_entry_expansion_and_mime_limits(): void
    {
        $path = base_path('tests/Fixtures/Documents/simple.docx');
        foreach ([['documents.max_entries', 2], ['documents.max_uncompressed_bytes', 100], ['documents.max_file_size_kb', 0]] as [$key, $limit]) {
            $original = config($key);
            config([$key => $limit]);
            try {
                app(DocxValidator::class)->validate($path);
                $this->fail('Limit bypassed');
            } catch (DocumentException) {
                $this->assertTrue(true);
            } finally {
                config([$key => $original]);
            }
        }
        $this->expectException(DocumentException::class);
        app(DocxValidator::class)->validate($path, 'docx', 'application/pdf');
    }

    public function test_only_docx_extension_size_and_quantity_are_allowed(): void
    {
        $batch = $this->batch([$this->upload()], false);
        foreach (['doc', 'docm', 'odt', 'rtf', 'txt', 'pdf', 'zip'] as $extension) {
            $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [$this->upload('bad.'.$extension)]], ['Accept' => 'application/json'])->assertStatus(422);
        }
        config(['documents.max_file_size_kb' => 1]);
        $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [$this->upload('big.docx', str_repeat('x', 2048))]], ['Accept' => 'application/json'])->assertStatus(422);
        config(['documents.max_files' => 1]);
        $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [$this->upload()]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_queued_private_partial_results_zip_history_and_idempotency(): void
    {
        $batch = $this->batch([$this->upload('relatorio.docx'), $this->upload('bad.docx', 'fake'), $this->upload('relatorio.docx')]);
        Queue::assertPushed(ProcessDocxToPdfJob::class, 3);
        $this->assertSame('queued', $batch->status->value);
        $this->assertSame(3, $batch->usage_files_count);
        $bad = $batch->files()->where('original_name', 'bad.docx')->firstOrFail();
        Storage::disk('processing')->assertMissing($bad->storage_path);
        $this->runBatch($batch);
        $this->runBatch($batch); // no duplicate conversion, charge or output
        $batch->refresh();
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(2, $batch->successful_files_count);
        $this->assertSame(1, $batch->failed_files_count);
        $this->assertSame(2, $batch->usage_files_count);
        $this->assertSame(3, $batch->files()->where('kind', 'output')->count());
        $input = $batch->files()->where('kind', 'input')->where('status', 'completed')->firstOrFail();
        $response = $this->get('/api/processings/'.$batch->uuid.'/pdf/'.$input->uuid)->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        $this->assertStringContainsString('relatorio.pdf', $response->headers->get('Content-Disposition'));
        $zipBytes = $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk()->streamedContent();
        $this->assertStringStartsWith('PK', $zipBytes);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('processing')->path($batch->output()->first()->storage_path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotSame($zip->getNameIndex(0), $zip->getNameIndex(1));
        $zip->close();
        $history = $this->getJson('/api/processings')->assertOk()->json('data.0');
        $this->assertSame('docx_to_pdf', $history['tool']);
        $this->assertArrayNotHasKey('original_name', $history);
        foreach ($batch->files()->where('kind', 'output')->get() as $output) {
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame('private', Storage::disk('processing')->getVisibility($output->storage_path));
            }
            $this->assertStringStartsWith('processings/', $output->storage_path);
            $this->assertStringNotContainsString(public_path(), Storage::disk('processing')->path($output->storage_path));
        }
        foreach ($this->workspaces as $workspace) {
            $this->assertDirectoryDoesNotExist($workspace);
        }
    }

    public function test_free_limits_and_avulso_remain_centralized(): void
    {
        $this->getJson('/api/tools/docx_to_pdf/config')->assertOk()->assertJsonPath('data.free.per_processing', 3)->assertJsonPath('data.packages', [])->assertJsonPath('data.checkout_available', false);
        $batch = $this->batch(array_fill(0, 4, $this->upload()), false);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(422);
        $this->getJson('/api/account/usage?tool=docx_to_pdf')->assertOk()->assertJsonPath('data.limits.per_processing', 3);
        $this->assertSame(0, $batch->fresh()->usage_files_count);
    }

    public function test_pro_limits_ttl_and_daily_quotas_are_independent_of_xml_usage(): void
    {
        $user = Auth::user();
        $user->forceFill(['stripe_customer_id' => 'cus_docx_test'])->save();
        Subscription::create(['user_id' => $user->id, 'provider_customer_id' => 'cus_docx_test',
            'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_docx_test',
            'status' => 'active', 'current_period_end' => now()->addMonth()]);
        $batch = $this->batch(array_fill(0, 4, $this->upload()));
        $this->assertSame('pro', $batch->entitlement_source);
        $this->assertGreaterThan(120, now()->diffInMinutes($batch->expires_at));
        $this->getJson('/api/account/usage?tool=docx_to_pdf')->assertJsonPath('data.limits.per_processing', 20)->assertJsonPath('data.limits.used_today', 4);
        $this->getJson('/api/account/usage?tool=xml-to-excel')->assertJsonPath('data.limits.used_today', 0);
        config(['documents.limits.pro.daily' => 4]);
        $next = $this->batch([$this->upload()], false);
        $this->postJson('/api/processings/'.$next->uuid.'/start')->assertStatus(422);
    }

    public function test_deletion_during_conversion_cannot_recreate_outputs(): void
    {
        $batch = $this->batch([$this->upload()]);
        $fake = \Mockery::mock(DocxToPdfConverter::class);
        $fake->shouldReceive('convert')->once()->andReturnUsing(function ($input, $workspace) use ($batch) {
            app(ProcessingService::class)->removeFiles($batch);
            throw new DocumentException(ProcessingFileError::ConversionFailed);
        });
        $this->app->instance(DocxToPdfConverter::class, $fake);
        $this->runBatch($batch);
        $this->assertSame('expired', $batch->fresh()->status->value);
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid));
        $this->assertSame(0, $batch->files()->where('kind', 'output')->count());
    }

    public function test_tampered_input_and_invalid_renderer_output_are_not_published(): void
    {
        $batch = $this->batch([$this->upload()]);
        $file = $batch->files()->where('kind', 'input')->first();
        Storage::disk('processing')->put($file->storage_path, 'changed');
        $this->runBatch($batch);
        $this->getJson('/api/processings/'.$batch->uuid.'/files')->assertJsonPath('data.0.error_code', 'storage_error');
        $other = $this->batch([$this->upload()]);
        $fake = \Mockery::mock(DocxToPdfConverter::class);
        $fake->shouldReceive('convert')->once()->andReturnUsing(function ($input, $workspace) {
            mkdir($workspace.'/output');
            file_put_contents($workspace.'/output/input.pdf', '<html>not PDF</html>');

            return $workspace.'/output/input.pdf';
        });
        $this->app->instance(DocxToPdfConverter::class, $fake);
        $this->runBatch($other);
        $this->getJson('/api/processings/'.$other->uuid.'/files')->assertJsonPath('data.0.error_code', 'pdf_output_invalid');
        $this->assertSame(0, $other->files()->where('kind', 'output')->count());
    }

    public function test_converter_errors_are_human_partial_and_always_cleaned(): void
    {
        foreach ([ProcessingFileError::ConversionTimeout, ProcessingFileError::ConverterUnavailable, ProcessingFileError::ConversionFailed, ProcessingFileError::PdfOutputInvalid] as $error) {
            $fake = \Mockery::mock(DocxToPdfConverter::class);
            $fake->shouldReceive('convert')->once()->andReturnUsing(function ($input, $workspace) use ($error) {
                $this->workspaces[] = $workspace;
                throw new DocumentException($error);
            });
            $this->app->instance(DocxToPdfConverter::class, $fake);
            $batch = $this->batch([$this->upload()]);
            $this->runBatch($batch);
            $this->assertSame('failed', $batch->fresh()->status->value);
            $this->assertSame(0, $batch->fresh()->usage_files_count);
            $this->getJson('/api/processings/'.$batch->uuid.'/files')->assertJsonPath('data.0.error_code', $error->value)->assertJsonPath('data.0.error_message', $error->message());
        }
        foreach ($this->workspaces as $workspace) {
            $this->assertDirectoryDoesNotExist($workspace);
        }
    }

    public function test_failed_conversion_neighbour_does_not_prevent_success(): void
    {
        $fake = \Mockery::mock(DocxToPdfConverter::class);
        $fake->shouldReceive('convert')->once()->andThrow(new DocumentException(ProcessingFileError::ConversionFailed));
        $fake->shouldReceive('convert')->once()->andReturnUsing(function ($input, $workspace) {
            mkdir($workspace.'/output');
            file_put_contents($workspace.'/output/input.pdf', self::pdf());

            return $workspace.'/output/input.pdf';
        });
        $this->app->instance(DocxToPdfConverter::class, $fake);
        $batch = $this->batch([$this->upload(), $this->upload()]);
        $this->runBatch($batch);
        $this->assertSame(1, $batch->fresh()->successful_files_count);
        $this->assertSame(1, $batch->fresh()->failed_files_count);
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    public function test_auth_ownership_expiry_purge_and_active_deletion(): void
    {
        $owner = Auth::user();
        $batch = $this->batch([$this->upload()]);
        $this->runBatch($batch);
        $input = $batch->files()->where('kind', 'input')->first();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['', '/files', '/result', '/pdf/'.$input->uuid] as $suffix) {
            $this->getJson('/api/processings/'.$batch->uuid.$suffix)->assertNotFound();
        }
        Auth::forgetGuards();
        $this->actingAs($owner, 'web');
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$batch->uuid.'/pdf/'.$input->uuid)->assertStatus(410);
        Storage::disk('local')->put('document-temporary/'.$batch->uuid.'/orphan', 'x');
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid));
        $this->assertSame([], Storage::disk('local')->allFiles('document-temporary/'.$batch->uuid));
        $active = $this->batch([$this->upload()]);
        $this->deleteJson('/api/processings/'.$active->uuid.'/files')->assertOk();
        $this->runBatch($active);
        $this->assertSame(0, $active->fresh()->usage_files_count);
        Auth::forgetGuards();
        auth('web')->logout();
        $this->getJson('/api/processings/'.$active->uuid.'/files')->assertUnauthorized();
    }

    public function test_exit_zero_does_not_imply_valid_pdf_and_command_uses_generated_arguments(): void
    {
        config(['documents.sandbox' => 'none', 'documents.binary' => 'C:\\Program Files\\LibreOffice\\program\\soffice.exe']);
        $directory = 'converter-'.Str::uuid();
        Storage::disk('local')->makeDirectory($directory);
        $workspace = Storage::disk('local')->path($directory);
        $runner = \Mockery::mock(ConversionProcessRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function ($args, $cwd, $timeout) use ($workspace) {
            $this->assertSame($workspace, $cwd);
            $this->assertSame(60, $timeout);
            $this->assertContains('--headless', $args);
            $this->assertStringEndsWith('/input.docx', end($args));
            $this->assertStringStartsWith('-env:UserInstallation=file:///', $args[1]);

            return true;
        })->andReturn(0);
        $this->app->instance(ConversionProcessRunner::class, $runner);
        try {
            app(LibreOfficeDocxToPdfConverter::class)->convert($workspace.'/input.docx', $workspace);
            $this->fail('Missing PDF accepted');
        } catch (DocumentException $e) {
            $this->assertSame(ProcessingFileError::PdfOutputInvalid, $e->error);
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
