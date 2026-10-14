<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Models\Processing;
use App\Models\ProcessingFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config([
            'feitoo.rate_limits.create_per_minute' => 1000,
            'feitoo.rate_limits.upload_per_minute' => 1000,
            'feitoo.rate_limits.access_per_minute' => 1000,
        ]);
    }

    private function createProcessing(): string
    {
        return $this->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertCreated()
            ->assertJsonPath('data.files_count', 0)->assertJsonPath('data.total_size', 0)
            ->assertJsonMissingPath('anonymous_token')->json('data.id');
    }

    private function xml(string $name = 'NF-e fornecedor janeiro.xml', string $content = '<document><item>example</item></document>'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(string $uuid, array $files)
    {
        return $this->postJson('/api/processings/'.$uuid.'/files', ['files' => $files]);
    }

    public function test_creates_owned_processing_with_public_uuid_and_expiration(): void
    {
        $this->travelTo(now()->startOfSecond());
        $uuid = $this->createProcessing();
        $processing = Processing::firstOrFail();
        $this->assertTrue(Str::isUuid($uuid));
        $this->assertSame('4', $uuid[14]);
        $this->assertNotSame((string) $processing->id, $uuid);
        $this->assertSame($this->owner->id, $processing->user_id);
        $this->assertSame(ProcessingStatus::Pending, $processing->status);
        $this->assertTrue($processing->expires_at->equalTo(now()->addMinutes(120)));
    }

    public function test_uuids_are_unique(): void
    {
        $this->assertNotSame($this->createProcessing(), $this->createProcessing());
    }

    public function test_rejects_unknown_tool_and_ignores_client_ownership_fields(): void
    {
        $this->postJson('/api/processings', ['tool' => 'parser'])->assertUnprocessable()->assertJsonValidationErrors('tool');
        $other = User::factory()->create();
        $this->postJson('/api/processings', ['tool' => 'xml-to-excel', 'user_id' => $other->id,
            'status' => 'completed', 'expires_at' => '2099-01-01', 'anonymous_token_hash' => 'attacker'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame($this->owner->id, Processing::firstOrFail()->user_id);
    }

    public function test_requires_authentication_for_creation_read_upload_and_delete(): void
    {
        $uuid = $this->createProcessing();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertUnauthorized();
        $this->getJson('/api/processings/'.$uuid)->assertUnauthorized();
        $this->upload($uuid, [$this->xml()])->assertUnauthorized();
        $this->deleteJson('/api/processings/'.$uuid.'/files')->assertUnauthorized();
        $this->assertDatabaseCount('processing_files', 0);
    }

    public function test_other_user_cannot_read_upload_or_delete_private_processing(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml()])->assertOk();
        $file = ProcessingFile::firstOrFail();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/processings/'.$uuid)->assertNotFound();
        $this->upload($uuid, [$this->xml()])->assertNotFound();
        $this->deleteJson('/api/processings/'.$uuid.'/files')->assertNotFound();
        Storage::disk('processing')->assertExists($file->storage_path);
        $this->assertDatabaseCount('processing_files', 1);
        $this->assertSame(ProcessingStatus::Pending, Processing::firstOrFail()->status);
    }

    public function test_legacy_ownerless_processing_is_inaccessible_and_still_purgeable(): void
    {
        $processing = Processing::create(['tool' => 'xml-to-excel', 'status' => ProcessingStatus::Pending, 'expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings/'.$processing->uuid)->assertNotFound();
        $this->upload($processing->uuid, [$this->xml()])->assertNotFound();
        $this->deleteJson('/api/processings/'.$processing->uuid.'/files')->assertNotFound();
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame(ProcessingStatus::Expired, $processing->fresh()->status);
    }

    public function test_owner_can_read_only_safe_resource_fields(): void
    {
        $uuid = $this->createProcessing();
        $response = $this->getJson('/api/processings/'.$uuid)->assertOk()->assertJsonPath('data.id', $uuid)
            ->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.metadata')->assertJsonMissingPath('anonymous_token');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['id', 'tool', 'status', 'files_count', 'processed_files_count', 'successful_files_count',
            'failed_files_count', 'total_size', 'started_at', 'completed_at', 'expires_at', 'created_at', 'preview', 'result'], array_keys($response->json('data')));
    }

    public function test_internal_sequential_id_is_not_a_route_key(): void
    {
        $this->createProcessing();
        $this->getJson('/api/processings/'.Processing::firstOrFail()->id)->assertNotFound();
    }

    public function test_uploads_multiple_valid_xmls_with_private_random_names_checksum_and_expiration(): void
    {
        $uuid = $this->createProcessing();
        $disk = Storage::disk('processing');
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('putFileAs')->twice()->withArgs(fn ($directory, $upload, $name, $options) => $directory === 'processings/'.$uuid.'/input'
            && Str::isUuid(substr($name, 0, -4))
            && $options['visibility'] === 'private')->passthru();
        Storage::set('processing', $proxy);
        $content = '<document><item>example</item></document>';
        $response = $this->upload($uuid, [$this->xml(), $this->xml('other.xml', '<root/>')])
            ->assertOk()->assertJsonPath('data.files_count', 2)->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_size', strlen($content) + strlen('<root/>'));
        $file = ProcessingFile::firstOrFail();
        $this->assertSame('processing', $file->storage_disk);
        $this->assertSame('NF-e fornecedor janeiro.xml', $file->original_name);
        $this->assertNotSame($file->original_name, $file->stored_name);
        $this->assertSame($file->uuid.'.xml', $file->stored_name);
        $this->assertSame('processings/'.$uuid.'/input/'.$file->stored_name, $file->storage_path);
        Storage::disk('processing')->assertExists($file->storage_path);
        // Windows does not expose POSIX chmod visibility; assert private write options above.
        $this->assertSame(hash('sha256', $content), $file->checksum);
        $this->assertTrue($file->expires_at->equalTo($file->processing->expires_at));
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->assertStringNotContainsString('stored_name', $response->getContent());
        $this->assertStringNotContainsString('original_name', $response->getContent());
    }

    public function test_processing_disk_configuration_is_private_and_not_served(): void
    {
        $this->assertSame(storage_path('app/private/processing'), config('filesystems.disks.processing.root'));
        $this->assertFalse(config('filesystems.disks.processing.serve'));
        $this->assertSame('private', config('filesystems.disks.processing.visibility'));
        $this->get('/storage/processings/example/input/example.xml')->assertNotFound();
    }

    public function test_rejects_invalid_extension(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml('document.zip')])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertDatabaseCount('processing_files', 0);
    }

    public function test_rejects_file_larger_than_configured_limit(): void
    {
        $uuid = $this->createProcessing();
        $file = UploadedFile::fake()->create('large.xml', 10241, 'text/xml');
        $this->upload($uuid, [$file])->assertUnprocessable()->assertJsonValidationErrors('files.0');
    }

    public function test_rejects_missing_empty_or_non_file_uploads(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [])->assertUnprocessable();
        $this->upload($uuid, ['not a file'])->assertUnprocessable();
        $this->upload($uuid, [$this->xml('empty.xml', '')])->assertUnprocessable();
    }

    public function test_rejects_fake_xml_content_and_malformed_xml(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml('fake.xml', "\x89PNG\r\n\x1a\n".str_repeat("\0", 100))])->assertUnprocessable();
        $this->upload($uuid, [$this->xml('plain.xml', 'not xml')])->assertUnprocessable();
        $this->upload($uuid, [$this->xml('broken.xml', '<root><item></root>')])->assertUnprocessable();
    }

    public function test_rejects_xxe_and_entity_bombs_without_resolving_entities(): void
    {
        $uuid = $this->createProcessing();
        $loaderCalls = 0;
        $previous = libxml_get_external_entity_loader();
        $probe = static function () use (&$loaderCalls) {
            $loaderCalls++;

            return null;
        };
        libxml_set_external_entity_loader($probe);
        try {
            foreach ([
                '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>',
                '<!DOCTYPE foo SYSTEM "https://example.invalid/hostile.dtd"><foo/>',
                '<!DOCTYPE foo [<!ENTITY a "bomb"><!ENTITY b "&a;&a;&a;">]><foo>&b;</foo>',
                mb_convert_encoding('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>', 'UTF-16', 'UTF-8'),
            ] as $hostile) {
                $this->upload($uuid, [$this->xml('hostile.xml', $hostile)])->assertUnprocessable();
            }
            $this->assertSame(0, $loaderCalls);
            $this->assertSame($probe, libxml_get_external_entity_loader());
        } finally {
            libxml_set_external_entity_loader($previous);
        }
        $this->assertDatabaseCount('processing_files', 0);
        $this->assertSame([], Storage::disk('processing')->allFiles());
    }

    public function test_limits_depth_and_nodes_without_leaking_parser_errors(): void
    {
        $uuid = $this->createProcessing();
        config(['feitoo.uploads.max_xml_depth' => 3, 'feitoo.uploads.max_xml_nodes' => 10]);
        $this->upload($uuid, [$this->xml('deep.xml', str_repeat('<x>', 8).str_repeat('</x>', 8))])->assertUnprocessable();
        $this->upload($uuid, [$this->xml('many.xml', '<root>'.str_repeat('<x/>', 20).'</root>')])->assertUnprocessable();
        $response = $this->upload($uuid, [$this->xml('broken.xml', '<secret-sensitive-content>')])->assertUnprocessable();
        $this->assertStringNotContainsString('secret-sensitive-content', $response->getContent());
    }

    public function test_batch_is_rejected_atomically_when_one_file_is_invalid(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml(), $this->xml('invalid.xml', 'invalid')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('processing')->allFiles());
        $this->assertDatabaseCount('processing_files', 0);
        $this->assertSame(0, Processing::firstOrFail()->files_count);
    }

    public function test_file_count_is_limited_per_request_and_across_requests(): void
    {
        $uuid = $this->createProcessing();
        config(['feitoo.uploads.max_files' => 2, 'feitoo.uploads.max_processing_files' => 2]);
        $this->upload($uuid, [$this->xml(), $this->xml(), $this->xml()])->assertUnprocessable();
        $this->upload($uuid, [$this->xml()])->assertOk();
        $this->upload($uuid, [$this->xml(), $this->xml()])->assertUnprocessable();
        $this->assertDatabaseCount('processing_files', 1);
        $this->assertSame(1, Processing::firstOrFail()->files_count);
    }

    public function test_expired_and_non_pending_processings_cannot_receive_files(): void
    {
        $this->travelTo(now()->startOfSecond());
        $uuid = $this->createProcessing();
        $processing = Processing::firstOrFail();
        $processing->update(['status' => ProcessingStatus::Queued]);
        $this->upload($uuid, [$this->xml()])->assertConflict();
        $processing->update(['status' => ProcessingStatus::Pending, 'expires_at' => now()->subSecond()]);
        $this->upload($uuid, [$this->xml()])->assertGone();
        $processing->update(['expires_at' => now()]);
        $this->upload($uuid, [$this->xml()])->assertGone();
        $this->assertDatabaseCount('processing_files', 0);
    }

    public function test_purge_removes_expired_files_and_orphans_but_keeps_technical_records(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml()])->assertOk();
        $file = ProcessingFile::firstOrFail();
        Storage::disk('processing')->put('processings/'.$uuid.'/input/orphan.xml', '<root/>');
        Processing::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        Storage::disk('processing')->assertMissing($file->storage_path);
        $this->assertSame([], Storage::disk('processing')->allFiles());
        $this->assertSame(ProcessingStatus::Expired, $file->processing->fresh()->status);
        $this->assertNotNull($file->fresh()->removed_at);
        $this->assertNull($file->fresh()->original_name);
        $this->assertDatabaseCount('processings', 1);
        $this->assertDatabaseCount('processing_files', 1);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
    }

    public function test_purge_skips_unexpired_processings_and_tolerates_missing_files(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml()])->assertOk();
        $file = ProcessingFile::firstOrFail();
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        Storage::disk('processing')->assertExists($file->storage_path);
        Storage::disk('processing')->deleteDirectory('processings/'.$uuid);
        Processing::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame(ProcessingStatus::Expired, Processing::firstOrFail()->status);
    }

    public function test_manual_deletion_removes_files_and_is_idempotent(): void
    {
        $uuid = $this->createProcessing();
        $this->upload($uuid, [$this->xml()])->assertOk();
        $file = ProcessingFile::firstOrFail();
        $this->deleteJson('/api/processings/'.$uuid.'/files')
            ->assertOk()->assertJsonPath('data.status', 'expired');
        Storage::disk('processing')->assertMissing($file->storage_path);
        $this->assertNotNull($file->fresh()->removed_at);
        $this->assertNull($file->fresh()->original_name);
        $this->deleteJson('/api/processings/'.$uuid.'/files')->assertOk();
        $this->upload($uuid, [$this->xml()])->assertGone();
        $this->assertDatabaseCount('processing_files', 1);
    }

    public function test_purge_continues_after_failure_and_retries_on_next_run(): void
    {
        $badUuid = $this->createProcessing();
        $goodUuid = $this->createProcessing();
        Processing::query()->update(['expires_at' => now()->subSecond()]);
        $disk = Storage::disk('processing');
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('deleteDirectory')->with('processings/'.$badUuid)->once()->andThrow(new \RuntimeException('sensitive path'));
        $proxy->shouldReceive('deleteDirectory')->with('processings/'.$goodUuid)->once()->andReturn(true);
        Storage::set('processing', $proxy);
        Log::shouldReceive('warning')->once()->with('Expired processing cleanup failed.', ['processing_uuid' => $badUuid]);
        $this->artisan('feitoo:purge-expired-processings')->assertFailed();
        $this->assertSame(ProcessingStatus::Pending, Processing::where('uuid', $badUuid)->firstOrFail()->status);
        $this->assertSame(ProcessingStatus::Expired, Processing::where('uuid', $goodUuid)->firstOrFail()->status);
        Storage::set('processing', $disk);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame(ProcessingStatus::Expired, Processing::where('uuid', $badUuid)->firstOrFail()->status);
    }

    public function test_failed_upload_compensates_files_and_rolls_back_metadata_without_sensitive_logging(): void
    {
        $uuid = $this->createProcessing();
        $disk = Storage::disk('processing');
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('putFileAs')->once()->passthru();
        $proxy->shouldReceive('putFileAs')->once()->andThrow(new \RuntimeException('sensitive filename and private path'));
        Storage::set('processing', $proxy);
        Log::shouldReceive('error')->once()->with('Processing request failed.', Mockery::on(fn ($context) => $context === ['processing_uuid' => $uuid, 'exception_type' => \RuntimeException::class]));
        $response = $this->upload($uuid, [$this->xml(), $this->xml('second.xml')])->assertStatus(500);
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseCount('processing_files', 0);
        $this->assertSame(0, Processing::firstOrFail()->files_count);
        $this->assertSame(ProcessingStatus::Pending, Processing::firstOrFail()->status);
    }

    public function test_create_and_upload_have_native_rate_limits(): void
    {
        config(['feitoo.rate_limits.create_per_minute' => 1, 'feitoo.rate_limits.upload_per_minute' => 1]);
        $uuid = $this->createProcessing();
        $this->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->upload($uuid, [$this->xml()])->assertOk();
        $this->upload($uuid, [$this->xml()])->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
