<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_hashed_password_and_authenticated_session(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Maria', 'email' => ' MARIA@EXAMPLE.COM ',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password', 'google_id' => 'forged',
        ])->assertCreated()->assertJsonPath('data.email', 'maria@example.com')
            ->assertJsonPath('data.google_linked', false)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.google_id');
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
        $this->assertNull($user->google_id);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_registration_rejects_duplicates_and_invalid_fields(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $this->postJson('/api/auth/register', ['name' => 'Other', 'email' => 'EXISTING@EXAMPLE.COM',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/register', ['email' => 'invalid', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_correct_password_logs_in_and_rotates_session_id(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com', 'password' => 'a-strong-password']);
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $oldSession = session()->getId();
        $this->postJson('/api/auth/login', ['email' => ' MARIA@EXAMPLE.COM ', 'password' => 'a-strong-password'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
    }

    public function test_invalid_login_returns_the_same_error_for_wrong_password_and_unknown_email(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);
        $first = $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $second = $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($first->json(), $second->json());
        $this->assertGuest('web');
    }

    public function test_google_only_account_cannot_login_with_password(): void
    {
        User::factory()->create(['email' => 'google@example.com', 'password' => null, 'google_id' => 'google-subject']);
        $this->postJson('/api/auth/login', ['email' => 'google@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_logout_invalidates_session_and_blocks_me(): void
    {
        $user = User::factory()->create(['password' => 'a-strong-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'a-strong-password'])->assertOk();
        $oldSession = session()->getId();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertNotSame($oldSession, session()->getId());
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_and_user_alias_require_authentication_and_return_safe_fields(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/me')->assertOk()->assertExactJson(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'google_linked' => false,
            'has_password' => true, 'created_at' => $user->created_at->toIso8601String(),
        ]]);
        $this->getJson('/api/user')->assertOk()->assertJsonMissingPath('data.password');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_state_changing_auth_and_processing_requests_enforce_csrf(): void
    {
        // Laravel skips CSRF in tests by default: explicitly exercise the real middleware.
        $this->app['env'] = 'local';
        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'wrong'])->assertStatus(419);
        $this->postJson('/api/auth/register', [])->assertStatus(419);
        $this->postJson('/api/auth/google/start', ['intent' => 'login'])->assertStatus(419);
        $this->actingAs(User::factory()->create(), 'web');
        $this->withHeader('Origin', 'http://localhost:4200')->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertStatus(419);
    }
}
