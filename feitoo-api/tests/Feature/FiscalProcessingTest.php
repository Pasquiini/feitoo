<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessFiscalFilesJob;
use App\Models\Processing;
use App\Models\ProcessingFile;
use App\Models\ProcessingFileResult;
use App\Models\User;
use App\Services\FiscalProcessingService;
use App\Services\PrivateProcessingFileReader;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FiscalProcessingTest extends TestCase
{
    // No surrounding test transaction: exercise real afterCommit dispatch.
    use DatabaseMigrations;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config(['feitoo.rate_limits.create_per_minute' => 1000, 'feitoo.rate_limits.upload_per_minute' => 1000,
            'feitoo.rate_limits.access_per_minute' => 1000, 'feitoo.processing.queue_connection' => 'database']);
    }

    public function test_owner_starts_explicitly_and_job_contains_only_identifier(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $file = $this->file($processing);
        $this->postJson($this->url($processing).'/start')->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.processed_files_count', 0);
        Queue::assertPushed(ProcessFiscalFilesJob::class, function (ProcessFiscalFilesJob $job) use ($processing, $file): bool {
            $this->assertSame($processing->uuid, $job->processingUuid);
            $this->assertSame('database', $job->connection);
            $this->assertStringNotContainsString($file->storage_path, serialize($job));
            $this->assertStringNotContainsString('Indústria', serialize($job));

            return true;
        });
        $this->assertSame(ProcessingStatus::Queued, $processing->fresh()->status);
        $this->assertSame(ProcessingFileStatus::Pending, $file->fresh()->status);
        $this->assertDatabaseCount('processing_file_results', 0);
    }

    public function test_empty_batch_does_not_start(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $this->postJson($this->url($processing).'/start')->assertStatus(422);
        $this->assertSame(ProcessingStatus::Pending, $processing->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_start_and_listing_require_authentication(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $this->file($processing);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->postJson($this->url($processing).'/start')->assertUnauthorized();
        $this->getJson($this->url($processing).'/files')->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_other_user_cannot_start_or_list_batch_and_direct_service_also_checks_owner(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $this->file($processing);
        $other = User::factory()->create();
        Auth::forgetGuards();
        $this->actingAs($other, 'web');
        $this->postJson($this->url($processing).'/start')->assertNotFound();
        $this->getJson($this->url($processing).'/files')->assertNotFound();
        Queue::assertNothingPushed();
        $this->expectException(HttpException::class);
        app(FiscalProcessingService::class)->start($processing, $other);
    }

    public function test_duplicate_start_is_blocked_and_pushes_only_one_job(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $this->file($processing);
        $this->postJson($this->url($processing).'/start')->assertStatus(202);
        $this->postJson($this->url($processing).'/start')->assertConflict();
        Queue::assertPushed(ProcessFiscalFilesJob::class, 1);
    }

    public function test_expired_processing_cannot_start(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $this->file($processing);
        $processing->update(['expires_at' => now()->subSecond()]);
        $this->postJson($this->url($processing).'/start')->assertStatus(410);
        Queue::assertNothingPushed();
    }

    public function test_sync_queue_is_rejected_instead_of_processing_in_request(): void
    {
        Queue::fake();
        config(['feitoo.processing.queue_connection' => 'sync']);
        $processing = $this->batch();
        $this->file($processing);
        $this->postJson($this->url($processing).'/start')->assertStatus(503);
        $this->assertSame(ProcessingStatus::Pending, $processing->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_queue_dispatch_failure_marks_global_failure_without_leaking_exception(): void
    {
        Log::spy();
        $processing = $this->batch();
        $this->file($processing);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('SECRET storage/path.xml CNPJ'));
        $response = $this->postJson($this->url($processing).'/start')->assertStatus(503);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertSame(ProcessingStatus::Failed, $processing->fresh()->status);
        $this->assertNotNull($processing->fresh()->completed_at);
        $this->assertSame(0, $processing->fresh()->usage_files_count);
        $this->assertNull($processing->fresh()->entitlement_source);
        Log::shouldHaveReceived('error')->with('Fiscal queue dispatch failed.', ['processing_uuid' => $processing->uuid, 'exception_type' => RuntimeException::class])->once();
    }

    public function test_handler_sets_processing_then_persists_minimal_result_and_finishes(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        $disk = Storage::disk('processing');
        $disk = Mockery::mock($disk)->makePartial();
        Storage::shouldReceive('disk')->with('processing')->andReturn($disk);
        $disk->shouldReceive('readStream')->with($file->storage_path)->twice()->andReturnUsing(function () use ($processing, $file) {
            $this->assertSame(ProcessingStatus::Processing, $processing->fresh()->status);
            $this->assertContains($file->fresh()->status, [ProcessingFileStatus::Processing, ProcessingFileStatus::Completed]);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $this->fixture());
            rewind($stream);

            return $stream;
        });
        $this->runJob($processing);
        $result = $file->fresh()->result;
        $this->assertNotNull($result);
        $this->assertSame('55', $result->model);
        $this->assertSame('35261000000000000100550010000001231000001234', $result->access_key);
        $this->assertSame('123', $result->number);
        $this->assertSame('001', $result->series);
        $this->assertSame('2026-10-01T09:10:11-03:00', $result->issued_at);
        $this->assertTrue($result->issued_at_has_time);
        $this->assertSame('00000000000100', $result->issuer_document);
        $this->assertSame('Indústria Fictícia São João', $result->issuer_name);
        $this->assertSame('00000000000200', $result->recipient_document);
        $this->assertSame('Comércio Fictício Açúcar', $result->recipient_name);
        $this->assertSame(2, $result->items_count);
        $this->assertSame('25.00', $result->total_value);
        $this->assertFalse(Schema::hasColumn('processing_file_results', 'parsed_payload'));
        $this->assertSame(ProcessingFileStatus::Completed, $file->fresh()->status);
        $this->assertNotNull($file->fresh()->processed_at);
        $this->assertNull($file->fresh()->error_code);
        $this->assertSummary($processing, 1, 1, 0);
    }

    public function test_mixed_batch_records_errors_per_file_and_completes_with_partial_success(): void
    {
        $processing = $this->queuedBatch();
        $valid = $this->file($processing);
        $invalid = $this->file($processing, '<NFe>');
        $unsupported = $this->file($processing, '<CTe xmlns="http://www.portalfiscal.inf.br/cte"/>');
        $missing = $this->file($processing, '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"/>');
        $this->runJob($processing);
        $this->assertSame(ProcessingFileStatus::Completed, $valid->fresh()->status);
        foreach ([[$invalid, ProcessingFileError::InvalidXml], [$unsupported, ProcessingFileError::UnsupportedDocument], [$missing, ProcessingFileError::MissingRequiredField]] as [$file, $error]) {
            $this->assertSame(ProcessingFileStatus::Failed, $file->fresh()->status);
            $this->assertSame($error, $file->fresh()->error_code);
            $this->assertSame($error->message(), $file->fresh()->error_message);
            $this->assertNull($file->fresh()->result);
        }
        $this->assertSummary($processing, 4, 1, 3);
        $this->assertDatabaseCount('processing_file_results', 1);
    }

    public function test_all_bad_files_fail_export_without_output(): void
    {
        $processing = $this->queuedBatch();
        $this->file($processing, '<bad/>');
        $this->file($processing, '<bad/>');
        $this->runJob($processing);
        $this->assertSummary($processing, 2, 0, 2);
    }

    public function test_missing_private_storage_is_only_a_file_error(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        Storage::disk('processing')->delete($file->storage_path);
        $this->runJob($processing);
        $this->assertSame(ProcessingFileError::StorageError, $file->fresh()->error_code);
        $this->assertSummary($processing, 1, 0, 1);
    }

    public function test_checksum_mismatch_prevents_parsing_tampered_content(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        Storage::disk('processing')->put($file->storage_path, '<root/>');
        $this->runJob($processing);
        $this->assertSame(ProcessingFileError::StorageError, $file->fresh()->error_code);
        $this->assertDatabaseCount('processing_file_results', 0);
    }

    public function test_date_only_and_high_precision_summary_are_preserved(): void
    {
        $processing = $this->queuedBatch();
        $xml = file_get_contents(__DIR__.'/../Fixtures/Fiscal/nfe-minimal.xml');
        $xml = str_replace('</infNFe>', '<total><ICMSTot><vNF>123456789012345.1234567890</vNF></ICMSTot></total></infNFe>', $xml);
        $file = $this->file($processing, $xml);
        $this->runJob($processing);
        $result = $file->fresh()->result;
        $this->assertSame('2012-03-04', $result->issued_at);
        $this->assertFalse($result->issued_at_has_time);
        $this->assertSame('00000000000', $result->recipient_document);
        $this->assertSame('123456789012345.1234567890', $result->total_value);
    }

    public function test_global_database_failure_rolls_back_current_file_but_preserves_previous_success(): void
    {
        $processing = $this->queuedBatch();
        $first = $this->file($processing);
        $second = $this->file($processing);
        Event::listen('eloquent.created: '.ProcessingFileResult::class, function (ProcessingFileResult $result) use ($second): void {
            if ((int) $result->processing_file_id === (int) $second->id) {
                throw new RuntimeException('SECRET fiscal persistence error');
            }
        });
        try {
            $this->runJob($processing);
            $this->fail('Expected persistence failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fiscal processing attempt failed.', $exception->getMessage());
        }
        $this->assertSame(ProcessingFileStatus::Completed, $first->fresh()->status);
        $this->assertSame(ProcessingFileStatus::Pending, $second->fresh()->status);
        $this->assertNull($second->fresh()->result);
        $this->assertDatabaseCount('processing_file_results', 1);
        (new ProcessFiscalFilesJob($processing->uuid))->failed(new RuntimeException('exhausted'));
        $this->assertSame(ProcessingStatus::Failed, $processing->fresh()->status);
        $this->assertSame(1, $processing->fresh()->successful_files_count);
        $this->assertSame(1, $processing->fresh()->processed_files_count);
    }

    public function test_reexecution_does_not_duplicate_results_or_change_completion_time(): void
    {
        $processing = $this->queuedBatch();
        $this->file($processing);
        $this->runJob($processing);
        $completed = $processing->fresh()->completed_at;
        $this->travel(5)->minutes();
        $this->runJob($processing);
        $this->assertDatabaseCount('processing_file_results', 1);
        $this->assertTrue($processing->fresh()->completed_at->equalTo($completed));
        $this->assertSummary($processing, 1, 1, 0);
    }

    public function test_resuming_processing_skips_already_terminal_files(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        $this->runJob($processing);
        $original = $file->fresh()->result->id;
        $processing->update(['status' => ProcessingStatus::Processing, 'completed_at' => null]);
        $this->file($processing, '<bad/>');
        $this->runJob($processing);
        $this->assertSame($original, $file->fresh()->result->id);
        $this->assertSummary($processing, 2, 1, 1);
        $this->assertDatabaseCount('processing_file_results', 1);
    }

    public function test_pending_processing_is_not_run_by_a_stale_job(): void
    {
        $processing = $this->batch();
        $file = $this->file($processing);
        $this->runJob($processing);
        $this->assertSame(ProcessingStatus::Pending, $processing->fresh()->status);
        $this->assertSame(ProcessingFileStatus::Pending, $file->fresh()->status);
    }

    public function test_two_users_batches_are_isolated_even_when_worker_runs(): void
    {
        $mine = $this->queuedBatch();
        $this->file($mine);
        $other = $this->queuedBatch(User::factory()->create());
        $file = $this->file($other);
        $this->runJob($mine);
        $this->assertSame(ProcessingStatus::Queued, $other->fresh()->status);
        $this->assertSame(ProcessingFileStatus::Pending, $file->fresh()->status);
        $this->getJson($this->url($other))->assertNotFound();
        $this->getJson($this->url($other).'/files')->assertNotFound();
        $this->deleteJson($this->url($other).'/files')->assertNotFound();
    }

    public function test_file_resource_has_only_safe_metadata_and_errors_never_include_xml(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing, '<NFe>SECRET_CNPJ_and_private_content');
        $this->runJob($processing);
        $response = $this->getJson($this->url($processing).'/files')->assertOk()
            ->assertJsonPath('data.0.id', $file->uuid)->assertJsonPath('data.0.error_code', 'invalid_xml')
            ->assertJsonPath('data.0.original_name', 'fixture.xml');
        foreach (['storage_path', 'stored_name', 'checksum', 'processing_id', 'result', 'access_key'] as $field) {
            $response->assertJsonMissingPath('data.0.'.$field);
        }
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString($file->storage_path, $response->getContent());
        $this->getJson($this->url($processing))->assertOk()->assertJsonPath('data.failed_files_count', 1)
            ->assertJsonPath('data.processed_files_count', 1)->assertJsonMissingPath('data.metadata');
    }

    public function test_manual_deletion_removes_fiscal_summaries_and_job_cannot_restore_them(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        $this->runJob($processing);
        $this->deleteJson($this->url($processing).'/files')->assertOk()->assertJsonPath('data.status', 'expired');
        Storage::disk('processing')->assertMissing($file->storage_path);
        $this->assertSame(ProcessingFileStatus::Removed, $file->fresh()->status);
        $this->assertNull($file->fresh()->original_name);
        $this->assertDatabaseCount('processing_file_results', 0);
        $this->runJob($processing);
        $this->assertDatabaseCount('processing_file_results', 0);
        $this->assertSame(ProcessingStatus::Expired, $processing->fresh()->status);
    }

    public function test_expiration_before_worker_purges_without_reading_or_persisting_results(): void
    {
        $processing = $this->queuedBatch();
        $file = $this->file($processing);
        $processing->update(['expires_at' => now()->subSecond()]);
        $this->runJob($processing);
        $this->assertSame(ProcessingStatus::Expired, $processing->fresh()->status);
        $this->assertSame(ProcessingFileStatus::Removed, $file->fresh()->status);
        $this->assertDatabaseCount('processing_file_results', 0);
        Storage::disk('processing')->assertMissing($file->storage_path);
    }

    public function test_scheduler_purge_removes_normalized_results_too(): void
    {
        $processing = $this->queuedBatch();
        $this->file($processing);
        $this->runJob($processing);
        $processing->update(['expires_at' => now()->subSecond()]);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertDatabaseCount('processing_file_results', 0);
    }

    public function test_global_failure_callback_preserves_existing_counts_and_cannot_overwrite_expired_batch(): void
    {
        $processing = $this->queuedBatch();
        $job = new ProcessFiscalFilesJob($processing->uuid);
        $job->failed(new RuntimeException('private error'));
        $this->assertSame(ProcessingStatus::Failed, $processing->fresh()->status);
        $this->assertNotNull($processing->fresh()->completed_at);
        $processing->update(['status' => ProcessingStatus::Expired]);
        $job->failed(new RuntimeException('private error'));
        $this->assertSame(ProcessingStatus::Expired, $processing->fresh()->status);
    }

    public function test_global_exception_is_sanitized_before_laravel_failed_job_logging(): void
    {
        Log::spy();
        $processing = $this->queuedBatch();
        $service = Mockery::mock(FiscalProcessingService::class);
        $service->shouldReceive('process')->once()->with($processing->uuid)->andThrow(new RuntimeException('SECRET XML /private/path'));
        try {
            (new ProcessFiscalFilesJob($processing->uuid))->handle($service);
            $this->fail('Expected global error');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fiscal processing attempt failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Log::shouldHaveReceived('error')->with('Fiscal batch attempt failed.', ['processing_uuid' => $processing->uuid, 'exception_type' => RuntimeException::class])->once();
    }

    public function test_native_overlap_lock_discards_duplicate_without_running_handler(): void
    {
        $job = new ProcessFiscalFilesJob((string) Str::uuid());
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 75);
        $this->assertTrue($lock->get());
        $called = false;
        try {
            $middleware->handle($job, function () use (&$called): void {
                $called = true;
            });
            $this->assertFalse($called);
        } finally {
            $lock->release();
        }
        $middleware->handle($job, function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function test_real_database_queue_worker_executes_handler_without_external_worker(): void
    {
        config(['queue.default' => 'database']);
        $processing = $this->batch();
        $this->file($processing);
        $this->postJson($this->url($processing).'/start')->assertStatus(202);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(ProcessingStatus::Queued, $processing->fresh()->status);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSummary($processing, 1, 1, 0);
    }

    public function test_upload_does_not_start_automatically_and_is_blocked_after_queueing(): void
    {
        Queue::fake();
        $processing = $this->batch();
        $xml = UploadedFile::fake()->createWithContent('fixture.xml', $this->fixture());
        $this->post($this->url($processing).'/files', ['files' => [$xml]], ['Accept' => 'application/json'])->assertOk();
        Queue::assertNothingPushed();
        $this->postJson($this->url($processing).'/start')->assertStatus(202);
        $this->post($this->url($processing).'/files', ['files' => [$xml]], ['Accept' => 'application/json'])->assertConflict();
    }

    public function test_preview_summarizes_all_documents_but_limits_rows_and_exposes_only_ui_fields(): void
    {
        $processing = $this->queuedBatch();
        for ($i = 0; $i < 6; $i++) {
            $this->file($processing);
        }
        $this->runJob($processing);
        $response = $this->getJson($this->url($processing))->assertOk()
            ->assertJsonPath('data.preview.summary.successful_documents_count', 6)
            ->assertJsonPath('data.preview.summary.failed_documents_count', 0)
            ->assertJsonPath('data.preview.summary.items_count', 12)
            ->assertJsonPath('data.preview.summary.total_value', '150.00')
            ->assertJsonCount(5, 'data.preview.notes_preview')
            ->assertJsonCount(10, 'data.preview.items_preview')
            ->assertJsonPath('data.preview.notes_preview.0.number', '123');
        $this->assertSame(['code', 'product', 'quantity', 'unit_value', 'total_value', 'note_number', 'series', 'note_reference', 'issuer_name'],
            array_keys($response->json('data.preview.items_preview.0')));
        foreach (['access_key', 'issuer_document', 'storage_path', 'checksum', '<?xml'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($processing))->assertNotFound();
    }

    public function test_preview_samples_distinct_persisted_notes_without_reading_xml_and_supports_legacy_results(): void
    {
        $processing = $this->queuedBatch();
        $this->file($processing, str_replace(['<nNF>123</nNF>', '<serie>001</serie>'], ['<nNF>100</nNF>', '<serie>1</serie>'], $this->fixture()));
        $this->file($processing, str_replace(['<nNF>123</nNF>', '<serie>001</serie>'], ['<nNF>101</nNF>', '<serie>2</serie>'], $this->fixture()));
        $this->runJob($processing);
        $results = ProcessingFileResult::whereIn('processing_file_id', $processing->files()->select('id'))->orderBy('id')->get();
        foreach ($results as $index => $result) {
            $this->assertSame((string) (100 + $index), $result->number);
            $this->assertSame((string) (1 + $index), $result->series);
            $result->update(['number' => (string) (100 + $index), 'series' => (string) (1 + $index),
                'items_count' => 20, 'items_preview' => array_fill(0, 10, [
                    'code' => 'product-'.$index, 'product' => 'Product '.$index, 'quantity' => '1',
                    'unit_value' => '1.00', 'total_value' => '1.00'])]);
        }
        $reader = Mockery::mock(PrivateProcessingFileReader::class);
        $reader->shouldNotReceive('read');
        $this->app->instance(PrivateProcessingFileReader::class, $reader);
        $items = $this->getJson($this->url($processing))->assertOk()->assertJsonCount(10, 'data.preview.items_preview')
            ->assertJsonPath('data.preview.summary.items_count', 40)->json('data.preview.items_preview');
        foreach ($items as $item) {
            $index = $item['note_number'] === '100' ? 0 : 1;
            $this->assertSame((string) (1 + $index), $item['series']);
            $this->assertSame('product-'.$index, $item['code']);
            $this->assertSame(hash_hmac('sha256', (string) $results[$index]->id, (string) config('app.key')), $item['note_reference']);
        }
        $this->assertCount(2, array_unique(array_column($items, 'note_reference')));
        $results[0]->update(['items_preview' => null]);
        $this->getJson($this->url($processing))->assertOk()->assertJsonCount(10, 'data.preview.items_preview');
        $results[1]->update(['items_preview' => null]);
        $this->getJson($this->url($processing))->assertOk()->assertJsonCount(0, 'data.preview.items_preview');
    }

    public function test_preview_survives_partial_failures_and_disappears_after_deletion(): void
    {
        $processing = $this->queuedBatch();
        $this->file($processing);
        $this->file($processing, '<bad/>');
        $this->runJob($processing);
        $this->getJson($this->url($processing))->assertOk()
            ->assertJsonPath('data.preview.summary.successful_documents_count', 1)
            ->assertJsonPath('data.preview.summary.failed_documents_count', 1)
            ->assertJsonPath('data.preview.summary.items_count', 2)
            ->assertJsonPath('data.preview.summary.total_value', '25.00');
        $this->deleteJson($this->url($processing).'/files')->assertOk()->assertJsonPath('data.preview', null);
        $this->assertDatabaseCount('processing_file_results', 0);
        $this->getJson($this->url($processing))->assertJsonPath('data.preview', null);
    }

    public function test_preview_is_absent_for_pending_failed_and_expired_results(): void
    {
        $processing = $this->queuedBatch();
        $this->getJson($this->url($processing))->assertJsonPath('data.preview', null);
        $this->file($processing, '<bad/>');
        $this->runJob($processing);
        $this->getJson($this->url($processing))->assertJsonPath('data.preview', null);
        $valid = $this->queuedBatch();
        $this->file($valid);
        $this->runJob($valid);
        $this->travel(3)->hours();
        $this->getJson($this->url($valid))->assertJsonPath('data.preview', null);
    }

    public function test_preview_preserves_decimal_precision_and_reports_missing_totals(): void
    {
        $processing = $this->queuedBatch();
        $xml = file_get_contents(__DIR__.'/../Fixtures/Fiscal/nfe-minimal.xml');
        $this->file($processing, $xml);
        $this->file($processing, str_replace('</infNFe>', '<total><ICMSTot><vNF>123456789012345.1234567890</vNF></ICMSTot></total></infNFe>', $xml));
        $this->runJob($processing);
        $this->getJson($this->url($processing))->assertOk()
            ->assertJsonPath('data.preview.summary.total_value', '123456789012345.1234567890')
            ->assertJsonPath('data.preview.summary.documents_without_total_count', 1);
    }

    public function test_persisted_item_sample_is_bounded_for_large_documents(): void
    {
        $processing = $this->queuedBatch();
        $xml = $this->fixture();
        preg_match('/<det\b.*?<\/det>/s', $xml, $match);
        $extra = '';
        for ($i = 3; $i <= 15; $i++) {
            $extra .= preg_replace('/nItem="\d+"/', 'nItem="'.$i.'"', $match[0]);
        }
        $file = $this->file($processing, str_replace('</infNFe>', $extra.'</infNFe>', $xml));
        $this->runJob($processing);
        $this->assertCount(10, $file->fresh()->result->items_preview);
        $this->getJson($this->url($processing))->assertJsonPath('data.preview.summary.items_count', 15)
            ->assertJsonCount(10, 'data.preview.items_preview');
    }

    private function batch(?User $owner = null): Processing
    {
        return Processing::create(['user_id' => ($owner ?? $this->owner)->id, 'tool' => 'xml-to-excel',
            'status' => ProcessingStatus::Pending, 'expires_at' => now()->addHours(2)]);
    }

    public function test_item_capacity_is_enforced_before_workbook_generation(): void
    {
        config(['feitoo.uploads.max_processing_items' => 1]);
        $processing = $this->queuedBatch();
        $this->file($processing);
        $this->runJob($processing);
        $this->assertSame(ProcessingStatus::Failed, $processing->fresh()->status);
        $this->assertSame(0, $processing->files()->where('kind', ProcessingFileKind::Output)->count());
        $this->getJson($this->url($processing))->assertJsonPath('data.preview', null)
            ->assertJsonPath('data.result.available', false)
            ->assertJsonPath('data.result.error_message', 'O lote ultrapassou a capacidade de itens do Excel. Divida os documentos em lotes menores.');
    }

    private function queuedBatch(?User $owner = null): Processing
    {
        $batch = $this->batch($owner);
        $batch->update(['status' => ProcessingStatus::Queued]);

        return $batch;
    }

    private function file(Processing $batch, ?string $xml = null): ProcessingFile
    {
        $xml ??= $this->fixture();
        $uuid = (string) Str::uuid();
        $path = 'processings/'.$batch->uuid.'/input/'.$uuid.'.xml';
        Storage::disk('processing')->put($path, $xml);
        $file = $batch->files()->create(['uuid' => $uuid, 'kind' => ProcessingFileKind::Input,
            'original_name' => 'fixture.xml', 'stored_name' => $uuid.'.xml', 'storage_disk' => 'processing',
            'storage_path' => $path, 'mime_type' => 'application/xml', 'extension' => 'xml',
            'size' => strlen($xml), 'checksum' => hash('sha256', $xml), 'expires_at' => $batch->expires_at]);
        $batch->increment('files_count');
        $batch->increment('total_size', strlen($xml));

        return $file;
    }

    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/Fiscal/nfe-proc.xml');
    }

    private function url(Processing $batch): string
    {
        return '/api/processings/'.$batch->uuid;
    }

    private function runJob(Processing $batch): void
    {
        app()->call([(new ProcessFiscalFilesJob($batch->uuid)), 'handle']);
    }

    private function assertSummary(Processing $batch, int $processed, int $success, int $failed): void
    {
        $batch->refresh();
        $this->assertSame($success === 0 ? ProcessingStatus::Failed : ProcessingStatus::Completed, $batch->status);
        $this->assertSame($processed, $batch->processed_files_count);
        $this->assertSame($success, $batch->successful_files_count);
        $this->assertSame($failed, $batch->failed_files_count);
        $this->assertNotNull($batch->started_at);
        $this->assertNotNull($batch->completed_at);
    }
}
