<?php

namespace Tests\Feature;

use App\Models\Processing;
use App\Models\ProcessingFileResult;
use App\Models\User;
use App\Services\PrivateProcessingFileReader;
use App\Services\ProcessingPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_endpoints_require_authentication(): void
    {
        $this->getJson('/api/processings')->assertUnauthorized();
        $this->patchJson('/api/account/profile', ['name' => 'Maria'])->assertUnauthorized();
        $this->patchJson('/api/account/password', $this->passwordPayload())->assertUnauthorized();
    }

    public function test_history_is_isolated_sorted_paginated_and_contains_no_fiscal_preview(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->processing($owner, ['created_at' => now()->subMinutes(12 - $i)])->uuid;
        }
        $foreign = $this->processing($other);
        $this->actingAs($owner, 'web');
        DB::enableQueryLog();
        $response = $this->getJson('/api/processings?per_page=1000&user_id='.$other->id)->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $ids[11])->assertJsonPath('data.9.id', $ids[2]);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('items_preview', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['preview', 'storage_path', 'checksum', 'user_id', $foreign->uuid] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
        $this->getJson('/api/processings?page=2')->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $ids[1]);
        $this->getJson('/api/processings?page=0')->assertUnprocessable();
        $this->getJson('/api/processings?page=1000001')->assertUnprocessable();
    }

    public function test_equal_timestamps_have_deterministic_newest_first_order_and_empty_page_is_safe(): void
    {
        $owner = User::factory()->create();
        $first = $this->processing($owner);
        $second = $this->processing($owner);
        $this->actingAs($owner, 'web');
        $this->getJson('/api/processings')->assertJsonPath('data.0.id', $second->uuid)->assertJsonPath('data.1.id', $first->uuid);
        $this->getJson('/api/processings?page=2')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_available_expired_and_deleted_history_preserve_minimal_records(): void
    {
        Storage::fake('processing');
        $owner = User::factory()->create();
        $this->actingAs($owner, 'web');
        $batch = $this->processing($owner, ['status' => 'completed', 'files_count' => 2, 'successful_files_count' => 1, 'failed_files_count' => 1]);
        $this->createOutput($batch);
        $this->getJson('/api/processings')->assertJsonPath('data.0.result.available', true)
            ->assertJsonPath('data.0.can_delete_files', true)->assertJsonPath('data.0.files_deleted', false)
            ->assertJsonPath('data.0.failed_files_count', 1);
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings')->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.result.available', false)->assertJsonPath('data.0.files_deleted', false);
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertStatus(410);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk();
        $this->getJson('/api/processings')->assertJsonPath('data.0.files_deleted', true)
            ->assertJsonPath('data.0.can_delete_files', false)->assertJsonPath('data.0.result.available', false)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.files_count', 2);
        $this->assertDatabaseHas('processings', ['id' => $batch->id]);
    }

    public function test_foreign_download_and_deletion_are_rejected(): void
    {
        Storage::fake('processing');
        $batch = $this->processing(User::factory()->create(), ['status' => 'completed']);
        $output = $this->createOutput($batch);
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/processings/'.$batch->uuid.'/result')->assertNotFound();
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertNotFound();
        $this->assertNull($output->fresh()->removed_at);
    }

    public function test_profile_updates_only_session_owner_name_and_ignores_sensitive_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $originalPassword = $owner->password;
        $this->actingAs($owner, 'web');
        $this->patchJson('/api/account/profile', ['name' => '  Maria Silva  ', 'id' => $other->id,
            'email' => 'forged@example.com', 'password' => 'forged', 'google_id' => 'forged'])
            ->assertOk()->assertJsonPath('data.name', 'Maria Silva')->assertJsonPath('data.email', $owner->email);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame($originalPassword, $owner->fresh()->password);
        $this->assertNull($owner->fresh()->google_id);
        $this->patchJson('/api/account/profile', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/account/profile', ['name' => str_repeat('x', 121)])->assertUnprocessable();
        $this->patchJson('/api/account/profile', ['name' => '<script>name</script>'])->assertUnprocessable();
        $this->patchJson('/api/account/profile', ['name' => "Na\0me"])->assertUnprocessable();
        $this->patchJson('/api/account/profile/'.$other->id, ['name' => 'forged'])->assertNotFound();
    }

    public function test_password_checks_current_password_rotates_session_and_new_password_logs_in(): void
    {
        $owner = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($owner, 'web');
        $oldSession = session()->getId();
        $this->patchJson('/api/account/password', $this->passwordPayload())->assertOk();
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertTrue(Hash::check('new-password-123', $owner->fresh()->password));
        $this->assertFalse(Hash::check('old-password-123', $owner->fresh()->password));
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'old-password-123'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'new-password-123'])->assertOk();
    }

    public function test_incorrect_missing_current_password_and_invalid_new_password_cannot_change_hash(): void
    {
        $owner = User::factory()->create(['password' => 'old-password-123']);
        $hash = $owner->password;
        $this->actingAs($owner, 'web');
        $this->patchJson('/api/account/password', [...$this->passwordPayload(), 'current_password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/account/password', ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password_confirmation' => 'mismatch'],
            ['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)]] as $invalid) {
            $this->patchJson('/api/account/password', [...$this->passwordPayload(), ...$invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertSame($hash, $owner->fresh()->password);
    }

    public function test_google_only_user_has_safe_flags_and_cannot_set_local_password(): void
    {
        $owner = User::factory()->create(['password' => null, 'google_id' => 'secret-google-subject']);
        $this->actingAs($owner, 'web');
        $response = $this->getJson('/api/me')->assertOk()->assertJsonPath('data.has_password', false)->assertJsonPath('data.google_linked', true);
        $this->assertSame(['id', 'name', 'email', 'google_linked', 'has_password', 'created_at'], array_keys($response->json('data')));
        foreach (['password', 'remember_token', 'google_id', 'secret-google-subject'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
        $this->patchJson('/api/account/password', $this->passwordPayload())->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertNull($owner->fresh()->password);
    }

    public function test_account_writes_require_csrf_even_without_origin_and_are_private(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->app['env'] = 'local';
        $this->patchJson('/api/account/profile', ['name' => 'Maria'])->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/account/password', $this->passwordPayload())->assertStatus(419);
    }

    public function test_history_totals_use_only_persisted_decimal_values_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $batch = $this->processing($owner, ['status' => 'completed', 'successful_files_count' => 2]);
        $this->fiscalResult($batch, '9007199254740993.10');
        $this->fiscalResult($batch, '0.19');
        $foreign = $this->processing(User::factory()->create(), ['successful_files_count' => 1]);
        $this->fiscalResult($foreign, '999.99');
        $this->mock(PrivateProcessingFileReader::class)->shouldNotReceive('read');
        $this->mock(ProcessingPreviewService::class)->shouldNotReceive('make');
        $this->actingAs($owner, 'web');
        $response = $this->getJson('/api/processings')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total_value', '9007199254740993.29');
        $this->assertStringNotContainsString($foreign->uuid, $response->getContent());
        $batch->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', '9007199254740993.29')
            ->assertJsonPath('data.0.status', 'expired');
    }

    public function test_history_missing_incomplete_and_deleted_totals_are_null_but_real_zero_is_preserved(): void
    {
        Storage::fake('processing');
        $owner = User::factory()->create();
        $batch = $this->processing($owner, ['status' => 'completed', 'successful_files_count' => 1]);
        $this->actingAs($owner, 'web');
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', null);
        $result = $this->fiscalResult($batch, null);
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', null);
        $result->update(['total_value' => '0.00']);
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', '0.00');
        $batch->update(['successful_files_count' => 2]);
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', null);
        $batch->update(['successful_files_count' => 1]);
        $this->deleteJson('/api/processings/'.$batch->uuid.'/files')->assertOk();
        $this->getJson('/api/processings')->assertJsonPath('data.0.total_value', null);
    }

    private function fiscalResult(Processing $batch, ?string $total): ProcessingFileResult
    {
        $file = $this->createOutput($batch);
        $file->update(['kind' => 'input']);

        return $file->result()->create(['document_type' => 'nfe', 'model' => '55',
            'access_key' => str_repeat('1', 44), 'number' => '1', 'series' => '1',
            'issued_at' => '2026-10-10', 'issued_at_has_time' => false,
            'items_count' => 1, 'total_value' => $total]);
    }

    private function passwordPayload(): array
    {
        return ['current_password' => 'old-password-123', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
    }

    private function processing(User $owner, array $attributes = []): Processing
    {
        return Processing::create(['user_id' => $owner->id, 'tool' => 'xml-to-excel', 'status' => 'queued',
            'expires_at' => now()->addHours(2), ...$attributes]);
    }

    private function createOutput(Processing $batch)
    {
        $uuid = (string) Str::uuid();

        return $batch->files()->create(['uuid' => $uuid, 'kind' => 'output', 'status' => 'completed',
            'original_name' => 'result.xlsx', 'stored_name' => $uuid.'.xlsx', 'storage_disk' => 'processing',
            'storage_path' => 'processings/'.$batch->uuid.'/output/'.$uuid.'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx',
            'size' => 10, 'checksum' => str_repeat('a', 64), 'expires_at' => $batch->expires_at]);
    }
}
