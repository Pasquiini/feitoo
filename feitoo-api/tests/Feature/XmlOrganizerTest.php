<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Processing;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FiscalProcessingService;
use App\Services\OrganizerNames;
use App\Services\OrganizerReport;
use App\Services\XmlOrganizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

class XmlOrganizerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config(['feitoo.processing.queue_connection' => 'database',
            'feitoo.rate_limits.create_per_minute' => 1000, 'feitoo.rate_limits.upload_per_minute' => 1000, 'feitoo.rate_limits.access_per_minute' => 1000]);
    }

    private function xml(string $model = '55', int $number = 123): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/Fiscal/nfe-proc.xml'));
        $base = '35261000000000000100'.$model.'001'.str_pad((string) $number, 9, '0', STR_PAD_LEFT).'100000123';
        $sum = 0;
        for ($i = 42, $w = 2; $i >= 0; $i--, $w = $w === 9 ? 2 : $w + 1) {
            $sum += (int) $base[$i] * $w;
        }
        $dv = 11 - $sum % 11;
        $key = $base.($dv >= 10 ? 0 : $dv);

        return str_replace(['35261000000000000100550010000001231000001234', '<mod>55</mod>', '<nNF>123</nNF>', '<cDV>4</cDV>'],
            [$key, '<mod>'.$model.'</mod>', '<nNF>'.$number.'</nNF>', '<cDV>'.$key[43].'</cDV>'], $xml);
    }

    private function batch(array $xmls, array $options = [], ?array $names = null): Processing
    {
        $id = $this->postJson('/api/processings', ['tool' => 'xml-organizer', 'organizer' => $options])->assertCreated()->json('data.id');
        $uploads = [];
        foreach ($xmls as $i => $xml) {
            $uploads[] = UploadedFile::fake()->createWithContent($names[$i] ?? 'nota-'.$i.'.xml', $xml);
        }
        $this->post('/api/processings/'.$id.'/files', ['files' => $uploads], ['Accept' => 'application/json'])->assertOk();

        return Processing::where('uuid', $id)->firstOrFail();
    }

    private function runBatch(Processing $batch): Processing
    {
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(202);
        app(FiscalProcessingService::class)->process($batch->uuid);

        return $batch->fresh();
    }

    private function archive(Processing $batch): ZipArchive
    {
        $zip = new ZipArchive;
        $output = $batch->output()->firstOrFail();
        $this->assertTrue($zip->open(Storage::disk($output->storage_disk)->path($output->storage_path)));

        return $zip;
    }

    public function test_all_criteria_nfe_nfce_optional_recipient_and_original_bytes(): void
    {
        $nfe = $this->xml();
        $nfce = preg_replace('/<dest>.*?<\/dest>/s', '', $this->xml('65', 124));
        $supplier = 'Industria_Ficticia_Sao_Joao_00000000000100';
        foreach (['year_month' => '2026/10', 'supplier' => $supplier, 'issuer_document' => '00000000000100', 'year_month_supplier' => '2026/10/'.$supplier, 'model' => 'NFe'] as $criterion => $folder) {
            $batch = $this->runBatch($this->batch([$nfe, $nfce], ['criterion' => $criterion]));
            $this->assertSame('completed', $batch->status->value);
            $this->assertSame(2, $batch->usage_files_count);
            $rows = array_values($batch->metadata['organizer_files']);
            $this->assertSame($folder, $rows[0]['folder']);
            $this->assertSame($criterion === 'model' ? 'NFCe' : $folder, $rows[1]['folder']);
            $this->assertStringStartsWith('NFe_', $rows[0]['final_name']);
            $this->assertStringStartsWith('NFCe_', $rows[1]['final_name']);
            $zip = $this->archive($batch);
            $this->assertSame($nfe, $zip->getFromName($rows[0]['archive_path']));
            $this->assertSame($nfce, $zip->getFromName($rows[1]['archive_path']));
            $this->assertNotFalse($zip->locateName('Relatorios/relatorio_organizacao.xlsx'));
            $zip->close();
            $this->assertSame([], Storage::disk('local')->allFiles('organizer-temporary/'.$batch->uuid));
        }
    }

    public function test_hash_duplicates_key_conflicts_and_no_overwrites(): void
    {
        $xml = $this->xml();
        $changed = str_replace('TESTE-001', 'OUTRO-001', $xml);
        $batch = $this->runBatch($this->batch([$xml, $xml, $changed], ['duplicate_action' => 'keep'], ['igual.xml', 'igual.xml', 'igual.xml']));
        $rows = array_values($batch->metadata['organizer_files']);
        $this->assertSame('Conteúdo idêntico', $rows[1]['duplicate_kind']);
        $this->assertSame('Mesma chave, conteúdo diferente', $rows[2]['duplicate_kind']);
        $this->assertTrue($rows[0]['key_conflict']);
        $this->assertTrue($rows[1]['key_conflict']);
        $this->assertTrue($rows[2]['key_conflict']);
        $this->assertCount(3, array_unique(array_column($rows, 'archive_path')));
        $this->assertStringContainsString('__2.xml', $rows[1]['final_name']);
        $zip = $this->archive($batch);
        $this->assertSame($xml, $zip->getFromName($rows[0]['archive_path']));
        $this->assertSame($xml, $zip->getFromName($rows[1]['archive_path']));
        $this->assertSame($changed, $zip->getFromName($rows[2]['archive_path']));
        $this->assertSame(5, $zip->numFiles);
        $zip->close();
        $summary = $this->getJson('/api/processings/'.$batch->uuid)->assertOk()->json('data.organizer.summary');
        $this->assertSame(2, $summary['duplicates_count']);
        $this->assertSame(1, $summary['conflicting_keys_count']);
        $this->assertSame(1, $summary['suppliers_count']);
        $this->assertSame(3, $summary['documents_by_month']['2026/10']);
        // Every successfully returned fiscal input is a unit, including explicitly retained copies.
        $this->assertSame(3, $batch->usage_files_count);
    }

    public function test_separate_duplicates_and_disable_detection_report_and_standard_names(): void
    {
        $xml = $this->xml();
        $batch = $this->runBatch($this->batch([$xml, $xml, str_replace('TESTE-001', 'OUTRO', $xml)]));
        $rows = array_values($batch->metadata['organizer_files']);
        $this->assertStringStartsWith('Duplicados/Conteudo_Identico/', $rows[1]['archive_path']);
        $this->assertStringStartsWith('Duplicados/Mesma_Chave/', $rows[2]['archive_path']);
        $batch = $this->runBatch($this->batch([$xml, $xml], ['detect_duplicates' => false, 'report' => false, 'standardize_names' => false], ['minha_nota.xml', 'minha_nota.xml']));
        $rows = array_values($batch->metadata['organizer_files']);
        $this->assertNull($rows[1]['duplicate_kind']);
        $this->assertSame('minha_nota.xml', $rows[0]['final_name']);
        $this->assertSame('minha_nota__2.xml', $rows[1]['final_name']);
        $zip = $this->archive($batch);
        $this->assertFalse($zip->locateName('Relatorios/relatorio_organizacao.xlsx'));
        $this->assertSame(3, $zip->numFiles);
        $zip->close();
    }

    public function test_partial_invalid_unknown_dates_and_missing_data_are_truthfully_classified(): void
    {
        $minimal = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe><ide><mod>65</mod></ide></infNFe></NFe>';
        $batch = $this->runBatch($this->batch([$this->xml(), '<NFe>', '<CTe/>', str_replace('2026-10-01T09:10:11-03:00', '2026-02-31T09:10:11-03:00', $this->xml()), $minimal]));
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(2, $batch->successful_files_count);
        $this->assertSame(3, $batch->failed_files_count);
        $this->assertSame(2, $batch->usage_files_count);
        $rows = array_values($batch->metadata['organizer_files']);
        foreach ([1, 2, 3] as $i) {
            $this->assertStringStartsWith('Arquivos_Com_Problemas/', $rows[$i]['archive_path']);
        }
        $this->assertSame('Outros', $rows[2]['type']);
        $this->assertNull($rows[2]['number']);
        $this->assertNull($rows[4]['access_key']);
        $this->assertNull($rows[4]['issued_at']);
        $this->assertSame('Sem_Data', $rows[4]['folder']);
        $this->assertSame('nota-4.xml', $rows[4]['final_name']);
        $summary = app(XmlOrganizerService::class)->summary($batch);
        $this->assertSame(5, $summary['preserved_count']);
        $this->assertSame(3, $summary['invalid_count']);
        $zip = $this->archive($batch);
        $this->assertSame('<NFe>', $zip->getFromName($rows[1]['archive_path']));
        $zip->close();
    }

    public function test_only_problem_files_still_return_safe_originals_without_usage(): void
    {
        $batch = $this->runBatch($this->batch(['<broken>', '<unknown/>']));
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(0, $batch->usage_files_count);
        $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk();
        $byModel = $this->runBatch($this->batch(['<CTe/>'], ['criterion' => 'model']));
        $this->assertSame('Outros', array_values($byModel->metadata['organizer_files'])[0]['folder']);
        $this->assertSame(0, $byModel->usage_files_count);
    }

    public function test_safe_names_path_traversal_and_report_formula_injection(): void
    {
        $names = app(OrganizerNames::class);
        foreach (['../../etc/passwd', 'C:\\Windows\\system32', '/absolute', '..', 'CON', 'AUX', 'a:b?*<>|', 'Fornecedor / .. / Café'] as $name) {
            $segment = $names->segment($name);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $segment);
            $this->assertStringNotContainsString('..', $segment);
        }
        $xml = str_replace('Indústria Fictícia São João', '=HYPERLINK("https://example.test")', $this->xml());
        $batch = $this->runBatch($this->batch([$xml], ['criterion' => 'supplier', 'standardize_names' => false], ['=formula.xml']));
        $row = array_values($batch->metadata['organizer_files'])[0];
        $this->assertStringNotContainsString('..', $row['archive_path']);
        $zip = $this->archive($batch);
        Storage::disk('local')->put('report-test.xlsx', $zip->getFromName('Relatorios/relatorio_organizacao.xlsx'));
        $zip->close();
        $book = IOFactory::load(Storage::disk('local')->path('report-test.xlsx'));
        $this->assertSame('s', $book->getSheet(0)->getCell('I2')->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $book->getSheet(0)->getCell('I2')->getValue());
        $this->assertSame('s', $book->getSheet(0)->getCell('D2')->getDataType());
        $this->assertStringContainsString('não consultado', $book->getSheet(0)->getCell('Q2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_options_security_upload_and_plan_limits_are_validated_before_queue(): void
    {
        $this->postJson('/api/processings', ['tool' => 'xml-organizer', 'organizer' => ['criterion' => '../unsafe']])->assertUnprocessable();
        $batch = $this->batch([$this->xml(), $this->xml('65')]);
        foreach (['<?xml version="1.0"?><!DOCTYPE root [<!ENTITY x SYSTEM "file:///etc/passwd">]><root>&x;</root>', 'PK'.str_repeat('a', 50), "<xml>\0</xml>", str_repeat('<x>', 80).str_repeat('</x>', 80)] as $contents) {
            $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [UploadedFile::fake()->createWithContent('unsafe.xml', $contents)]], ['Accept' => 'application/json'])->assertStatus(422);
        }
        config(['monetization.free.per_processing' => 1]);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(422);
        Queue::assertNothingPushed();
        $this->assertSame(0, $batch->fresh()->usage_files_count);
        config(['monetization.free.per_processing' => 20, 'monetization.free.daily' => 1]);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(422);
        config(['feitoo.uploads.max_file_size_kb' => 1]);
        $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [UploadedFile::fake()->createWithContent('large.xml', $this->xml())]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_idempotency_history_download_owner_and_expiry(): void
    {
        $batch = $this->runBatch($this->batch([$this->xml()]));
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame(1, $batch->fresh()->usage_files_count);
        $this->assertSame(1, $batch->output()->count());
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertConflict();
        $this->getJson('/api/processings')->assertOk()->assertJsonPath('data.0.tool', 'xml-organizer');
        $this->get('/api/processings/'.$batch->uuid.'/result')->assertOk()->assertHeader('Content-Type', 'application/zip');
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['', '/files', '/result'] as $suffix) {
            $this->getJson('/api/processings/'.$batch->uuid.$suffix)->assertNotFound();
        }
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertNotFound();
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertStatus(410);
        Storage::disk('local')->put('organizer-temporary/'.$batch->uuid.'/orphan.xml', '<orphan/>');
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertArrayNotHasKey('organizer_files', $batch->fresh()->metadata);
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid));
        $this->assertSame([], Storage::disk('local')->allFiles('organizer-temporary/'.$batch->uuid));
    }

    public function test_zip_failure_retries_do_not_publish_or_double_charge_and_failure_releases_usage(): void
    {
        $batch = $this->batch([$this->xml()]);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(202);
        $real = app(XmlOrganizerService::class);
        $mock = \Mockery::mock(XmlOrganizerService::class)->makePartial();
        $mock->shouldReceive('processFile')->andReturnUsing(fn ($p, $f) => $real->processFile($p, $f));
        $mock->shouldReceive('generate')->once()->andThrow(new \RuntimeException('ZIP unavailable'));
        $this->app->instance(XmlOrganizerService::class, $mock);
        try {
            app(FiscalProcessingService::class)->process($batch->uuid);
            $this->fail('Expected ZIP failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('ZIP unavailable', $e->getMessage());
        }
        $this->assertSame(1, $batch->fresh()->usage_files_count);
        $this->assertSame(0, $batch->output()->count());
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertConflict();
        $this->app->instance(XmlOrganizerService::class, $real);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame(1, $batch->fresh()->usage_files_count);
        $this->assertSame(1, $batch->output()->count());
        $failed = $this->batch([$this->xml()]);
        $this->postJson('/api/processings/'.$failed->uuid.'/start')->assertStatus(202);
        app(FiscalProcessingService::class)->fail($failed->uuid);
        $this->assertSame(0, $failed->fresh()->usage_files_count);
    }

    public function test_pro_limits_and_usage_are_reused_and_failed_inputs_do_not_consume(): void
    {
        Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_test_organizer',
            'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_organizer', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        config(['monetization.free.per_processing' => 1, 'monetization.pro.per_processing' => 2, 'monetization.pro.daily' => 2]);
        $batch = $this->runBatch($this->batch([$this->xml(), '<broken>']));
        $this->assertSame('pro', $batch->entitlement_source);
        $this->assertSame(1, $batch->usage_files_count);
        $this->getJson('/api/account/usage?tool=xml-organizer')->assertOk()->assertJsonPath('data.plan', 'pro')->assertJsonPath('data.limits.remaining_today', 1);
        $above = $this->batch([$this->xml(), $this->xml('65')]);
        $this->postJson('/api/processings/'.$above->uuid.'/start')->assertStatus(422);
        $this->deleteJson('/api/processings/'.$above->uuid.'/files')->assertOk();
        $this->assertSame(0, $above->fresh()->usage_files_count);
        $this->getJson('/api/account/usage?tool=xml-to-excel')->assertOk()->assertJsonPath('data.limits.used_today', 0);
    }

    public function test_real_archive_cleanup_on_report_failure_and_retry(): void
    {
        $batch = $this->batch([$this->xml()]);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(202);
        $report = \Mockery::mock(OrganizerReport::class);
        $report->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Report storage unavailable'));
        $this->app->instance(OrganizerReport::class, $report);
        try {
            app(FiscalProcessingService::class)->process($batch->uuid);
            $this->fail('Expected report failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Report storage unavailable', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('organizer-temporary/'.$batch->uuid));
        $this->assertSame([], Storage::disk('processing')->allFiles('processings/'.$batch->uuid.'/output'));
        $this->assertSame(0, $batch->output()->count());
        $this->app->instance(OrganizerReport::class, new OrganizerReport);
        app(FiscalProcessingService::class)->process($batch->uuid);
        $this->assertSame('completed', $batch->fresh()->status->value);
        $this->assertSame(1, $batch->fresh()->usage_files_count);
    }

    public function test_cumulative_quantity_size_limits_unauthenticated_download_and_active_delete(): void
    {
        $batch = $this->batch([$this->xml()]);
        config(['feitoo.uploads.max_processing_files' => 1]);
        $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [UploadedFile::fake()->createWithContent('extra.xml', $this->xml())]], ['Accept' => 'application/json'])->assertStatus(422);
        config(['feitoo.uploads.max_processing_files' => 250, 'feitoo.uploads.max_total_size_kb' => 1]);
        $this->post('/api/processings/'.$batch->uuid.'/files', ['files' => [UploadedFile::fake()->createWithContent('extra.xml', $this->xml())]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson('/api/processings/'.$batch->uuid.'/start')->assertStatus(202);
        $this->assertSame(1, $batch->fresh()->usage_files_count);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk();
        $this->assertSame(0, $batch->fresh()->usage_files_count);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertUnauthorized();
    }

    public function test_tampered_original_is_not_archived_or_charged(): void
    {
        $batch = $this->batch([$this->xml(), $this->xml('65')]);
        $file = $batch->files()->where('kind', 'input')->orderByDesc('id')->firstOrFail();
        Storage::disk('processing')->put($file->storage_path, '<tampered/>');
        $batch = $this->runBatch($batch);
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(1, $batch->usage_files_count);
        $rows = array_values($batch->metadata['organizer_files']);
        $this->assertNull($rows[1]['archive_path']);
        $this->assertSame('storage_error', $file->fresh()->error_code->value);
        $this->assertSame(1, app(XmlOrganizerService::class)->summary($batch)['preserved_count']);
        $zip = $this->archive($batch);
        $this->assertSame(3, $zip->numFiles);
        $zip->close();
    }
}
