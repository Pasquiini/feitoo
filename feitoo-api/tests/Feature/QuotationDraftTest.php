<?php

namespace Tests\Feature;

use App\Models\QuotationDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuotationDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->freezeSecond();
        Storage::fake('quotation_drafts');
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
    }

    private function payload(): array
    {
        $party = ['name' => '', 'document' => '', 'email' => '', 'phone' => '', 'address' => ''];

        return ['number' => 'ORC-001', 'issue_date' => '', 'valid_until' => '',
            'issuer' => $party, 'customer' => $party,
            'items' => [['description' => '', 'quantity' => '0,', 'unit_price_cents' => null]],
            'discount_type' => 'fixed', 'discount_fixed_cents' => null, 'discount_percent' => '',
            'conditions' => '', 'notes' => '', 'template_id' => 'modern'];
    }

    private function create(?array $payload = null, ?string $key = null): array
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $payload ?? $this->payload()])
            ->assertCreated()->json('data');
    }

    private function url(array $draft): string
    {
        return '/api/quotations/drafts/'.$draft['uuid'];
    }

    public function test_partial_crud_is_private_and_encrypted(): void
    {
        $payload = $this->payload();
        $payload['issuer']['name'] = 'Pessoa Fictícia Secreta';
        $payload['money_inputs'] = ['prices' => ['12,345'], 'fixed_discount' => ''];
        $draft = $this->create($payload);
        $this->assertSame($payload, $draft['payload']);
        $this->assertSame(1, $draft['revision']);
        $this->assertEquals(86400, now()->diffInSeconds($draft['expires_at'], true));
        $raw = DB::table('quotation_drafts')->value('payload');
        $this->assertStringNotContainsString('Pessoa', $raw);
        $this->assertNotSame($payload, json_decode($raw, true));
        $this->getJson($this->url($draft))->assertOk()->assertJsonPath('data.payload', $payload)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.logo_path');
        $this->getJson('/api/quotations/drafts')->assertJsonPath('data.uuid', $draft['uuid']);
        $this->travel(1)->hours();
        $payload['notes'] = 'Texto <script>alert(1)</script> literal';
        $this->patchJson($this->url($draft), ['revision' => 1, 'schema_version' => 1, 'payload' => $payload])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.payload.notes', $payload['notes']);
        $this->assertTrue(QuotationDraft::first()->expires_at->equalTo(now()->addDay()->startOfSecond()));
        $this->deleteJson($this->url($draft), ['revision' => 2])->assertNoContent();
        $this->getJson($this->url($draft))->assertNotFound();
        $this->assertDatabaseCount('processings', 0);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_preview_mode_keeps_drafts_but_blocks_generation_and_checkout(): void
    {
        config(['quotations.enabled' => false]);
        $draft = $this->create();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url($draft).'/generations', ['revision' => 1])->assertStatus(503);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/quotations/credit-orders')->assertStatus(503);
        $this->getJson($this->url($draft))->assertOk();
        $this->assertDatabaseCount('processings', 0);
        $this->assertDatabaseCount('quotation_credit_orders', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_complete_editor_can_be_saved_without_processing(): void
    {
        $payload = $this->payload();
        $payload['issuer']['name'] = 'Prestador Fictício';
        $payload['customer']['name'] = 'Cliente Fictício';
        $payload['issue_date'] = '2026-10-22';
        $payload['valid_until'] = '2026-11-06';
        $payload['items'] = [['description' => 'Serviço', 'quantity' => '2,5', 'unit_price_cents' => 15000]];
        $payload['discount_fixed_cents'] = 100;
        $this->create($payload);
        $this->assertDatabaseCount('processings', 0);
    }

    public function test_paginated_drafts_exclude_other_accounts_expired_payloads_and_private_assets(): void
    {
        $expired = $this->create();
        QuotationDraft::where('uuid', $expired['uuid'])->update(['expires_at' => now()->subSecond()]);
        $latest = null;
        for ($i = 1; $i <= 11; $i++) {
            $payload = $this->payload();
            $payload['number'] = 'ORC-'.$i;
            $payload['customer']['name'] = 'Cliente da conta atual';
            $payload['notes'] = 'Conteúdo privado fora da listagem';
            $latest = $this->create($payload);
        }
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $other = $this->create();
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
        $first = $this->getJson('/api/quotations/drafts?page=1')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 11)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.uuid', $latest['uuid'])->assertJsonPath('data.0.number', 'ORC-11')
            ->assertJsonMissingPath('data.0.payload')->assertJsonMissingPath('data.0.logo_path')
            ->assertJsonMissingPath('data.0.user_id');
        $this->assertStringNotContainsString($other['uuid'], $first->getContent());
        $this->assertStringNotContainsString($expired['uuid'], $first->getContent());
        $this->assertStringNotContainsString('Conteúdo privado fora da listagem', $first->getContent());
        $this->getJson('/api/quotations/drafts?page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/quotations/drafts')->assertJsonPath('data.uuid', $latest['uuid']);
        $this->getJson('/api/quotations/drafts?page=0')->assertUnprocessable();
        $this->getJson('/api/quotations/drafts?page[]=1')->assertUnprocessable();
        $this->withHeader('X-Quotation-Owner', '999999')->getJson('/api/quotations/drafts?page=1')->assertUnauthorized();
        $this->flushHeaders();
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson('/api/quotations/drafts?page=1')->assertUnauthorized();
    }

    public function test_import_retries_are_idempotent_and_changed_body_conflicts(): void
    {
        $key = (string) Str::uuid();
        $draft = $this->create(key: $key);
        $this->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $this->payload()])
            ->assertOk()->assertJsonPath('data.uuid', $draft['uuid'])->assertJsonPath('data.revision', 1);
        $payload = $this->payload();
        $payload['number'] = 'changed';
        $this->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $payload])
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('quotation_drafts', 1);
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->create(key: $key);
        $this->assertDatabaseCount('quotation_drafts', 2);
    }

    public function test_optimistic_revision_rejects_stale_payload_logo_and_delete(): void
    {
        $draft = $this->create();
        $payload = $this->payload();
        $payload['number'] = 'new';
        $this->patchJson($this->url($draft), ['revision' => 1, 'schema_version' => 1, 'payload' => $payload])->assertOk();
        $this->patchJson($this->url($draft), ['revision' => 1, 'schema_version' => 1, 'payload' => $this->payload()])
            ->assertConflict()->assertJsonPath('code', 'draft_revision_conflict');
        $this->deleteJson($this->url($draft), ['revision' => 1])->assertConflict();
        $this->deleteJson($this->url($draft).'/logo', ['revision' => 1])->assertConflict();
        $this->putJson($this->url($draft).'/logo', ['revision' => 1, 'logo' => UploadedFile::fake()->image('logo.png')])->assertConflict();
        $this->assertSame('new', QuotationDraft::first()->payload['number']);
        Storage::disk('quotation_drafts')->assertDirectoryEmpty('/');
    }

    public function test_all_resources_return_404_to_other_users_and_require_authentication(): void
    {
        $draft = $this->create();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($draft))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson($this->url($draft), ['revision' => 1, 'schema_version' => 1, 'payload' => $this->payload()])->assertNotFound();
        $this->deleteJson($this->url($draft), ['revision' => 1])->assertNotFound();
        $this->getJson($this->url($draft).'/logo')->assertNotFound();
        $this->putJson($this->url($draft).'/logo', ['revision' => 1, 'logo' => UploadedFile::fake()->image('logo.png')])->assertNotFound();
        $this->deleteJson($this->url($draft).'/logo', ['revision' => 1])->assertNotFound();
        $this->getJson('/api/quotations/drafts')->assertJsonPath('data', null);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson($this->url($draft))->assertUnauthorized();
        $this->postJson('/api/quotations/drafts', [])->assertUnauthorized();
    }

    public function test_expiration_is_enforced_without_extending_on_reads_and_cleanup_is_scoped(): void
    {
        $draft = $this->create();
        $this->upload($draft);
        Storage::disk('quotation_drafts')->put('orphan/old.png', 'orphan');
        touch(Storage::disk('quotation_drafts')->path('orphan/old.png'), now()->subHours(2)->timestamp);
        Storage::disk('quotation_drafts')->put('orphan/recent.png', 'recent');
        Storage::fake('processing');
        Storage::disk('processing')->put('keep.txt', 'keep');
        $this->getJson($this->url($draft))->assertOk()->assertJsonPath('data.revision', 2);
        $this->travel(24)->hours();
        $this->getJson($this->url($draft))->assertStatus(410)->assertJsonPath('code', 'draft_expired');
        $this->getJson($this->url($draft).'/logo')->assertStatus(410);
        $this->patchJson($this->url($draft), ['revision' => 2, 'schema_version' => 1, 'payload' => $this->payload()])->assertStatus(410);
        $this->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $this->payload()])->assertStatus(410);
        $this->getJson('/api/quotations/drafts')->assertJsonPath('data', null);
        // Real file mtime is used by the orphan grace, independent of Carbon's test clock.
        $this->artisan('feitoo:purge-quotation-drafts')->assertSuccessful();
        $this->assertDatabaseCount('quotation_drafts', 0);
        $this->assertSame([], Storage::disk('quotation_drafts')->allFiles());
        Storage::disk('processing')->assertExists('keep.txt');
    }

    public function test_orphan_grace_retains_active_and_recent_files(): void
    {
        $draft = $this->create();
        $this->upload($draft);
        $disk = Storage::disk('quotation_drafts');
        $disk->put('old.png', 'old');
        touch($disk->path('old.png'), now()->subHours(2)->timestamp);
        $disk->put('recent.png', 'new');
        $this->artisan('feitoo:purge-quotation-drafts')->assertSuccessful();
        $disk->assertMissing('old.png');
        $disk->assertExists('recent.png');
        $disk->assertExists(QuotationDraft::first()->logo_path);
    }

    private function upload(array $draft, string $extension = 'png', int $revision = 1): void
    {
        $this->post($this->url($draft).'/logo', ['_method' => 'PUT', 'revision' => $revision,
            'logo' => UploadedFile::fake()->image('original.'.$extension, 30, 20)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.revision', $revision + 1);
    }

    public function test_png_jpeg_normalization_replacement_and_logo_removal(): void
    {
        $draft = $this->create();
        $this->upload($draft);
        $first = QuotationDraft::first()->logo_path;
        $this->getJson($this->url($draft).'/logo')->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->upload($draft, 'jpg', 2);
        Storage::disk('quotation_drafts')->assertMissing($first);
        $png = Storage::disk('quotation_drafts')->get(QuotationDraft::first()->logo_path);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($png)[2]);
        $this->assertSame(hash('sha256', $png), QuotationDraft::first()->logo_checksum);
        $this->assertNotSame($first, QuotationDraft::first()->logo_path);
        $this->deleteJson($this->url($draft).'/logo', ['revision' => 3])->assertOk()->assertJsonPath('data.revision', 4)->assertJsonPath('data.logo_checksum', null);
        $this->getJson($this->url($draft).'/logo')->assertNotFound();
        $this->deleteJson($this->url($draft), ['revision' => 4])->assertNoContent();
    }

    public function test_rollback_keeps_the_logo_when_removing_a_logo_or_draft_fails(): void
    {
        $draft = $this->create();
        $this->upload($draft);
        $path = QuotationDraft::first()->logo_path;
        $bytes = Storage::disk('quotation_drafts')->get($path);

        foreach (['saving', 'deleting'] as $operation) {
            $event = 'eloquent.'.$operation.': '.QuotationDraft::class;
            Event::listen($event, fn () => throw new \RuntimeException('Synthetic transaction failure'));
            try {
                $this->deleteJson($this->url($draft).($operation === 'saving' ? '/logo' : ''), ['revision' => 2])
                    ->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private');
            } finally {
                Event::forget($event);
            }
            $this->assertSame(2, QuotationDraft::first()->revision);
            $this->assertSame($path, QuotationDraft::first()->logo_path);
            $this->assertSame($bytes, Storage::disk('quotation_drafts')->get($path));
        }
    }

    public function test_invalid_logos_and_oversize_are_rejected_without_modifying_draft(): void
    {
        $draft = $this->create();
        foreach ([UploadedFile::fake()->createWithContent('fake.png', '<svg/>'),
            UploadedFile::fake()->create('large.jpg', 2049, 'image/jpeg'),
            UploadedFile::fake()->image('large.png', 2001, 2000),
            UploadedFile::fake()->createWithContent('remote.png', 'https://example.com/logo.png')] as $logo) {
            $this->putJson($this->url($draft).'/logo', ['revision' => 1, 'logo' => $logo])->assertUnprocessable();
        }
        $this->assertSame(1, QuotationDraft::first()->revision);
        Storage::disk('quotation_drafts')->assertDirectoryEmpty('/');
    }

    public function test_closed_payload_schema_types_and_limits(): void
    {
        $payload = $this->payload();
        $invalid = [];
        foreach (['user_id', 'plan_price', 'logo_path', 'logo_url', 'html', 'total_cents'] as $field) {
            $case = $payload;
            $case[$field] = 'forged';
            $invalid[] = $case;
        }
        $case = $payload;
        $case['issuer']['owner'] = 99;
        $invalid[] = $case;
        $case = $payload;
        $case['items'][0]['html'] = 'x';
        $invalid[] = $case;
        $case = $payload;
        $case['items'][0]['unit_price_cents'] = '100';
        $invalid[] = $case;
        $case = $payload;
        $case['items'][0]['unit_price_cents'] = -1;
        $invalid[] = $case;
        $case = $payload;
        $case['items'] = array_fill(0, 101, $payload['items'][0]);
        $invalid[] = $case;
        $case = $payload;
        $case['notes'] = str_repeat('x', 4001);
        $invalid[] = $case;
        $case = $payload;
        $case['template_id'] = '../other';
        $invalid[] = $case;
        $case = $payload;
        $case['number'] = "x\0";
        $invalid[] = $case;
        foreach ($invalid as $bad) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $bad])->assertUnprocessable();
        }
        $this->postJson('/api/quotations/drafts', ['schema_version' => 2, 'payload' => $payload])->assertUnprocessable();
        $this->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $payload, 'user_id' => 999])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'invalid')->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $payload])->assertUnprocessable();
        $this->assertDatabaseCount('quotation_drafts', 0);
    }

    public function test_changed_account_cookie_context_cannot_import_old_account_content(): void
    {
        $this->withHeader('X-Quotation-Owner', (string) ($this->owner->id + 1))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/quotations/drafts', ['schema_version' => 1, 'payload' => $this->payload()])->assertUnauthorized();
        $this->assertDatabaseCount('quotation_drafts', 0);
    }

    public function test_csrf_is_required_even_without_origin_and_errors_are_private(): void
    {
        $draft = $this->create();
        $this->app['env'] = 'local';
        foreach (['POST', 'PATCH', 'DELETE', 'PUT'] as $method) {
            $url = match ($method) {
                'POST' => '/api/quotations/drafts', 'PUT' => $this->url($draft).'/logo', default => $this->url($draft)
            };
            $this->json($method, $url, [])->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->withSession(['_token' => 'test-csrf'])->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->patchJson($this->url($draft), ['revision' => 1, 'schema_version' => 1, 'payload' => $this->payload()])->assertOk();
    }

    public function test_rate_limit_and_logout_block_access(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/quotations/drafts')->assertOk();
        }
        $this->getJson('/api/quotations/drafts')->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/quotations/drafts')->assertUnauthorized();
    }
}
