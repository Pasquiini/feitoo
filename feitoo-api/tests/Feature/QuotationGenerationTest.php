<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\GenerateQuotationPdfJob;
use App\Models\Processing;
use App\Models\QuotationDraft;
use App\Models\QuotationGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\FiscalProcessingService;
use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationGenerationService;
use App\Services\Quotations\QuotationPdfException;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class QuotationGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private QuotationGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->freezeSecond();
        config(['quotations.enabled' => true, 'quotations.sandbox' => 'php']);
        Storage::fake('quotation_drafts');
        Storage::fake('quotation_generations');
        Storage::fake('quotation_execution');
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        $this->service = app(QuotationGenerationService::class);
    }

    private function draft(): QuotationDraft
    {
        $party = ['name' => 'Pessoa Fictícia', 'document' => '', 'email' => '', 'phone' => '', 'address' => ''];

        return QuotationDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'import_key' => (string) Str::uuid(), 'import_hash' => str_repeat('a', 64), 'schema_version' => 1, 'revision' => 1,
            'payload' => ['number' => 'TEST-001', 'issue_date' => '2026-10-22', 'valid_until' => '2026-11-06',
                'issuer' => $party, 'customer' => $party, 'items' => [['description' => 'Serviço fictício', 'quantity' => '0,5', 'unit_price_cents' => 101]],
                'discount_type' => 'fixed', 'discount_fixed_cents' => 0, 'discount_percent' => '0', 'conditions' => '', 'notes' => '', 'template_id' => 'modern'],
            'expires_at' => now()->addDay()]);
    }

    private function start(?QuotationDraft $draft = null, ?string $key = null)
    {
        $draft ??= $this->draft();

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/quotations/drafts/'.$draft->uuid.'/generations', ['revision' => $draft->revision]);
    }

    private function work(string $uuid, ?QuotationExecutionRunner $runner = null): void
    {
        (new GenerateQuotationPdfJob($uuid))->handle($this->service, $runner ?? app(QuotationExecutionRunner::class));
    }

    private function quota(): object
    {
        return DB::table('quotation_daily_quotas')->first();
    }

    private function url(string $uuid): string
    {
        return '/api/processings/'.$uuid;
    }

    public function test_real_pdf_job_snapshot_logo_and_download_are_private_and_immutable(): void
    {
        $draft = $this->draft();
        $this->post('/api/quotations/drafts/'.$draft->uuid.'/logo', ['_method' => 'PUT', 'revision' => 1,
            'logo' => UploadedFile::fake()->image('logo.png', 60, 30)], ['Accept' => 'application/json'])->assertOk();
        $draft->refresh();
        $uuid = $this->start($draft)->assertStatus(202)->assertJsonPath('data.tool', 'quotation-generator')->json('data.id');
        $generation = QuotationGeneration::firstOrFail();
        $snapshotBytes = Storage::disk('quotation_generations')->get($generation->snapshot_path);
        $this->assertStringNotContainsString('Pessoa Fictícia', $snapshotBytes);
        $snapshot = json_decode(Crypt::decryptString($snapshotBytes), true);
        $this->assertSame(51, $snapshot['totals']['totalCents']);
        $this->assertSame(1, $this->quota()->reserved);
        $this->assertSame(0, $this->quota()->consumed);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringContainsString('GenerateQuotationPdfJob', $payload);
        $this->assertStringNotContainsString('Pessoa', $payload);
        $this->assertStringNotContainsString('snapshot_path', $payload);
        // Mutating and removing the draft/logo cannot affect the already reserved issuance.
        $this->deleteJson('/api/quotations/drafts/'.$draft->uuid, ['revision' => 2])->assertNoContent();
        $queueJob = Queue::connection('quotations')->pop('quotations');
        $this->assertNotNull($queueJob);
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('command')->once()->andReturnUsing(function (string $workspace) use ($snapshotBytes) {
            $executionSnapshot = file_get_contents($workspace.'/snapshot.enc');
            $this->assertNotSame($snapshotBytes, $executionSnapshot);
            $rejected = false;
            try {
                Crypt::decryptString($executionSnapshot);
            } catch (DecryptException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Renderer workspace must use its own ephemeral key.');

            return (new QuotationExecutionRunner)->command($workspace);
        });
        $this->app->instance(QuotationExecutionRunner::class, $runner);
        $queueJob->fire();
        $queueJob->delete();
        $processing = Processing::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ProcessingStatus::Completed, $processing->status, QuotationGeneration::first()->error_code ?? '');
        $this->assertSame(0, $this->quota()->reserved);
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertSame(7200, $processing->expires_at->timestamp - $processing->completed_at->timestamp);
        $this->getJson($this->url($uuid))->assertOk()->assertJsonPath('data.result.available', true)->assertJsonMissingPath('data.preview')->assertJsonMissingPath('data.quotation.snapshot_path');
        $response = $this->get($this->url($uuid).'/result')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $pdf = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->get($this->url($uuid).'/result')->assertOk();
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertCount(1, $processing->files()->where('kind', 'output')->get());
        $this->assertSame([], Storage::disk('quotation_execution')->allFiles($uuid));
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_idempotency_keys_and_same_revision_replay_do_not_enqueue_or_charge_twice(): void
    {
        $draft = $this->draft();
        $key = (string) Str::uuid();
        $uuid = $this->start($draft, $key)->assertStatus(202)->json('data.id');
        $this->start($draft, $key)->assertStatus(202)->assertJsonPath('data.id', $uuid);
        $alias = (string) Str::uuid();
        $this->start($draft, $alias)->assertStatus(202)->assertJsonPath('data.id', $uuid);
        $this->start($this->draft(), $alias)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('quotation_generations', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, $this->quota()->reserved);
    }

    public function test_parallel_reservations_are_counted_and_other_accounts_are_independent(): void
    {
        $this->start()->assertStatus(202);
        $this->start()->assertConflict()->assertJsonPath('code', 'generation_in_progress');
        $this->owner = User::factory()->create();
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
        $this->start()->assertStatus(202);
        $this->assertDatabaseCount('quotation_daily_quotas', 2);
    }

    public function test_free_is_one_success_per_account_day_and_survives_delete_and_purge(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $this->work($uuid);
        $this->start()->assertStatus(422)->assertJsonPath('code', 'free_quota_exhausted');
        $this->deleteJson($this->url($uuid).'/files')->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertDatabaseCount('quotation_generations', 1);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->start()->assertStatus(422)->assertJsonPath('code', 'free_quota_exhausted');
        $this->get($this->url($uuid).'/result')->assertStatus(410);
        $this->assertSame([], Storage::disk('quotation_generations')->allFiles());
    }

    public function test_sao_paulo_midnight_preserves_the_reservation_period_and_full_download_window(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22T23:59:50-03:00'));
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $this->assertSame('2026-10-22', $this->quota()->reservation_date);
        $this->travel(20)->seconds();
        $this->work($uuid);
        $this->assertSame('2026-10-22', QuotationGeneration::first()->reservation_date);
        $this->assertSame(1, $this->quota()->consumed);
        $this->getJson('/api/quotations/entitlements')->assertOk()->assertJsonPath('data.remaining', 1)->assertJsonPath('data.resets_at', '2026-10-24T00:00:00-03:00');
        $this->start()->assertStatus(202);
        $this->assertDatabaseCount('quotation_daily_quotas', 2);
    }

    public function test_invalid_content_revision_and_forbidden_fields_do_not_reserve(): void
    {
        $draft = $this->draft();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/drafts/'.$draft->uuid.'/generations', ['revision' => 2])->assertConflict();
        $payload = $draft->payload;
        $payload['issuer']['name'] = '';
        $draft->update(['payload' => $payload]);
        $this->start($draft)->assertStatus(422)->assertJsonPath('code', 'quotation_invalid');
        $payload['issuer']['name'] = 'Fictício';
        $payload['money_inputs'] = ['prices' => ['999,00'], 'fixed_discount' => '0,00'];
        $draft->update(['payload' => $payload]);
        $this->start($draft)->assertStatus(422)->assertJsonPath('code', 'quotation_invalid');
        $this->postJson('/api/quotations/drafts/'.$draft->uuid.'/generations', ['revision' => 1, 'plan_price' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('quotation_daily_quotas', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_failure_rolls_back_reservation_processing_snapshot_and_alias(): void
    {
        $draft = $this->draft();
        Queue::shouldReceive('connection')->once()->with('quotations')->andThrow(new \RuntimeException('private error'));
        $this->start($draft)->assertStatus(503)->assertJsonPath('code', 'queue_unavailable');
        $this->assertDatabaseCount('quotation_daily_quotas', 0);
        $this->assertDatabaseCount('quotation_generation_requests', 0);
        $this->assertDatabaseCount('processings', 0);
        $this->assertSame([], Storage::disk('quotation_generations')->allFiles());
    }

    public function test_duplicate_deliveries_and_expired_attempt_tokens_cannot_publish(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $first = $this->service->claim($uuid);
        $this->assertNull($this->service->claim($uuid));
        $this->travel(91)->seconds();
        $this->service->reconcile($uuid);
        $second = $this->service->claim($uuid);
        $this->assertNotSame($first->attempt_token, $second->attempt_token);
        $pdf = app(QuotationExecutionRunner::class)->render($second);
        $this->assertFalse($this->service->complete($first, $pdf));
        $this->assertSame(0, $this->quota()->consumed);
        $this->assertTrue($this->service->complete($second, $pdf));
        $this->assertFalse($this->service->complete($second, $pdf));
        $this->get($this->url($uuid).'/result')->assertOk();
        $this->work($uuid);
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertSame(1, Processing::where('uuid', $uuid)->first()->files()->where('kind', 'output')->count());
    }

    public function test_transient_failure_keeps_reservation_and_retry_succeeds_without_a_second_charge(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('render')->once()->andThrow(new QuotationPdfException('execution_timeout'));
        $this->work($uuid, $runner);
        $this->assertSame(1, $this->quota()->reserved);
        $this->assertSame(0, $this->quota()->consumed);
        $this->work($uuid);
        $this->assertSame('consumed', QuotationGeneration::first()->consumption_status);
        $this->assertSame(2, QuotationGeneration::first()->attempt);
        $this->assertSame(1, $this->quota()->consumed);
    }

    public function test_terminal_failure_releases_and_manual_retry_is_revision_checked_and_idempotent(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('render')->times(3)->andThrow(new QuotationPdfException('render_failed'));
        for ($i = 0; $i < 3; $i++) {
            $this->work($uuid, $runner);
        }
        $this->assertSame(0, $this->quota()->reserved);
        $this->assertSame(0, $this->quota()->consumed);
        $this->assertSame('released', QuotationGeneration::first()->consumption_status);
        $url = '/api/quotations/generations/'.$uuid.'/retry';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['attempt' => 2])->assertConflict();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, ['attempt' => 3])->assertStatus(202);
        $this->postJson($url, ['attempt' => 3])->assertStatus(202);
        $this->assertSame(1, $this->quota()->reserved);
        $this->work($uuid);
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertDatabaseCount('quotation_generations', 1);
    }

    public function test_abandoned_execution_and_pending_timeout_are_reconciled_and_release_only_once(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $claimed = $this->service->claim($uuid);
        $this->travel(31)->minutes();
        $this->artisan('feitoo:reconcile-quotation-generations')->assertSuccessful();
        $this->assertSame('released', QuotationGeneration::first()->consumption_status);
        $this->assertSame(0, $this->quota()->reserved);
        $this->assertNull($this->service->claim($uuid));
        $this->artisan('feitoo:reconcile-quotation-generations')->assertSuccessful();
        $this->assertSame(0, $this->quota()->consumed);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame([], Storage::disk('quotation_generations')->allFiles());
    }

    public function test_expiration_is_two_hours_after_completion_and_purge_keeps_consumption(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $this->travel(10)->minutes();
        $this->work($uuid);
        $this->travel(119)->minutes();
        $this->get($this->url($uuid).'/result')->assertOk();
        $this->travel(1)->minutes();
        $this->get($this->url($uuid).'/result')->assertStatus(410);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame(1, $this->quota()->consumed);
        $this->assertNotNull(QuotationGeneration::first()->purged_at);
    }

    public function test_ownership_auth_csrf_and_legacy_paths_are_enforced(): void
    {
        $draft = $this->draft();
        $uuid = $this->start($draft)->assertStatus(202)->json('data.id');
        $this->postJson('/api/processings', ['tool' => 'quotation-generator'])->assertUnprocessable();
        $this->postJson($this->url($uuid).'/start')->assertConflict();
        $this->postJson($this->url($uuid).'/files', ['files' => [UploadedFile::fake()->createWithContent('x.xml', '<xml/>')]])->assertConflict();
        $this->postJson($this->url($uuid).'/purchase')->assertUnprocessable();
        app(FiscalProcessingService::class)->process($uuid);
        $this->assertSame(ProcessingStatus::Queued, Processing::where('uuid', $uuid)->first()->status);
        $this->assertSame(1, $this->quota()->reserved);
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($uuid))->assertNotFound();
        $this->get($this->url($uuid).'/result')->assertNotFound();
        $this->start($draft)->assertNotFound();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/generations/'.$uuid.'/retry', ['attempt' => 1])->assertNotFound();
        $this->app['env'] = 'local';
        $this->start($this->draft())->assertStatus(419);
        $this->getJson('/api/quotations/entitlements')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.policy', 'explicit_source');
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson('/api/quotations/entitlements')->assertUnauthorized();
    }

    public function test_limiter_and_fail_closed_configuration_do_not_charge(): void
    {
        config(['quotations.enabled' => false]);
        config(['cors.allowed_origins' => ['https://quotation-staging.example.test']]);
        $this->withHeader('Origin', 'https://quotation-staging.example.test');
        $draft = $this->draft();
        for ($i = 0; $i < 10; $i++) {
            $this->start($draft)->assertStatus(503);
        }
        $this->start($draft)->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertHeader('Access-Control-Allow-Origin', 'https://quotation-staging.example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, Retry-After');
        $this->assertDatabaseCount('quotation_daily_quotas', 0);
    }

    public function test_actual_subprocess_wall_timeout_cleans_the_workspace_and_keeps_retry_reservation(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        config(['quotations.render_seconds' => 1]);
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('command')->once()->andReturn([PHP_BINARY, '-r', 'sleep(10);']);
        $this->work($uuid, $runner);
        $this->assertSame('execution_timeout', QuotationGeneration::first()->error_code);
        $this->assertSame(1, $this->quota()->reserved);
        $this->assertSame(0, $this->quota()->consumed);
        $this->assertSame([], Storage::disk('quotation_execution')->allFiles($uuid));
    }

    public function test_abandoned_max_attempts_release_and_old_workers_cannot_change_quota(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $claims = [];
        for ($i = 0; $i < 3; $i++) {
            $claims[] = $this->service->claim($uuid);
            $this->travel(91)->seconds();
            $this->service->reconcile($uuid);
        }
        $this->assertSame('released', QuotationGeneration::first()->consumption_status);
        $this->assertSame(0, $this->quota()->reserved);
        foreach ($claims as $claim) {
            $this->assertFalse($this->service->failAttempt($claim, 'render_failed'));
        }
        $this->assertSame(0, $this->quota()->consumed);
    }

    public function test_dedicated_orphan_cleanup_preserves_live_assets_and_other_tools(): void
    {
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $disk = Storage::disk('quotation_generations');
        $orphan = 'processings/'.Str::uuid().'/input/snapshot.enc';
        $disk->put($orphan, 'private orphan');
        touch($disk->path($orphan), now()->subHours(2)->timestamp);
        $execution = Storage::disk('quotation_execution');
        $temporary = Str::uuid().'/'.Str::uuid().'/snapshot.enc';
        $execution->put($temporary, 'private temp');
        touch($execution->path($temporary), now()->subMinutes(10)->timestamp);
        Storage::fake('processing');
        Storage::disk('processing')->put('unchanged.xml', '<xml/>');
        $this->artisan('feitoo:reconcile-quotation-generations')->assertSuccessful();
        $disk->assertMissing($orphan);
        $disk->assertExists(QuotationGeneration::first()->snapshot_path);
        $execution->assertMissing($temporary);
        Storage::disk('processing')->assertExists('unchanged.xml');
    }

    public function test_incomplete_pro_period_receives_free_without_fiscal_usage(): void
    {
        $subscription = Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_fictitious',
            'provider_price_id' => 'price_fictitious', 'provider_subscription_id' => 'sub_fictitious', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        $this->assertTrue($subscription->grantsPro());
        $fiscalBefore = app(EntitlementService::class)->usage($this->owner);
        $this->getJson('/api/quotations/entitlements')->assertOk()->assertJsonPath('data.source', 'free')
            ->assertJsonPath('data.policy', 'explicit_source')->assertJsonPath('data.daily_limit', 1);
        $uuid = $this->start()->assertStatus(202)->json('data.id');
        $processing = Processing::where('uuid', $uuid)->first();
        $this->assertSame('quotation_free', $processing->entitlement_source);
        $this->assertSame(0, $processing->usage_files_count);
        $this->assertNull($processing->usage_date);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertSame($fiscalBefore, app(EntitlementService::class)->usage($this->owner));
    }
}
