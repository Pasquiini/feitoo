<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    public function test_quotation_return_survives_google_success_and_cancellation(): void
    {
        $this->fakeGoogle();
        $response = $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => '/gerador-de-orcamentos'])->assertOk();
        $this->completeGoogle(session('state'))->assertRedirect('http://localhost:4200/gerador-de-orcamentos');
        $this->assertDatabaseCount('quotation_drafts', 0);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => '/gerador-de-orcamentos'])->assertOk();
        $this->get('/api/auth/google/callback?error=access_denied')
            ->assertRedirect('http://localhost:4200/login?google_error=invalid_google&returnTo=%2Fgerador-de-orcamentos');
    }

    public function test_quotation_return_rejects_external_urls_and_personal_query_parameters(): void
    {
        foreach (['https://example.com', '//example.com', '/gerador-de-orcamentos?name=private', '/gerador-de-orcamentos#private'] as $path) {
            $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => $path])->assertUnprocessable();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    private function fakeGoogle(array $identity = [], int $tokenStatus = 200): void
    {
        $handler = new MockHandler([
            new Response($tokenStatus, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'transient-google-access-token', 'refresh_token' => 'never-store-this', 'expires_in' => 3600,
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
                'sub' => 'google-subject-123', 'email' => 'google@example.com', 'email_verified' => true, 'name' => 'Google User',
            ], $identity))),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        config(['services.google.guzzle' => ['handler' => $stack]]);
        Socialite::forgetDrivers();
    }

    private function start(string $intent = 'login'): string
    {
        $response = $this->postJson('/api/auth/google/start', ['intent' => $intent])->assertOk();
        $url = $response->json('redirect_url');
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayHasKey('code_challenge', $query);
        $this->assertSame($query['state'], session('state'));

        return $query['state'];
    }

    private function completeGoogle(string $state)
    {
        // Real requests resolve a new provider: avoid caching a previous request in the test app.
        Socialite::forgetDrivers();

        return $this->get('/api/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'server-code']));
    }

    public function test_verified_google_identity_registers_user_and_logs_in_without_storing_tokens(): void
    {
        $this->fakeGoogle();
        $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/');
        $user = User::firstOrFail();
        $this->assertSame('google-subject-123', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertCount(2, $this->history);
        parse_str((string) $this->history[0]['request']->getBody(), $body);
        $this->assertSame('test-client', $body['client_id']);
        $this->assertSame('test-secret', $body['client_secret']);
        $this->assertSame('server-code', $body['code']);
        $this->assertNotEmpty($body['code_verifier']);
        $this->assertSame('Bearer transient-google-access-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('never-store-this', json_encode($user->getAttributes()));
        $this->assertStringNotContainsString('transient-google-access-token', json_encode($user->getAttributes()));
        $this->getJson('/api/me', ['Origin' => 'http://localhost:4200'])->assertOk()->assertJsonPath('signup_method', 'google');
        $this->getJson('/api/me', ['Origin' => 'http://localhost:4200'])->assertOk()->assertJsonMissingPath('signup_method');
    }

    public function test_invalid_state_never_contacts_google_or_creates_user(): void
    {
        $this->fakeGoogle();
        $this->start();
        $this->completeGoogle('forged-state')->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $this->assertCount(0, $this->history);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_invalid_google_code_is_rejected_without_leaking_oauth_response(): void
    {
        $this->fakeGoogle(tokenStatus: 400);
        $response = $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $this->assertStringNotContainsString('transient-google-access-token', $response->getContent());
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_google_requires_verified_email(): void
    {
        $this->fakeGoogle(['email_verified' => false]);
        $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_google_subject_logs_in_without_creating_duplicate_even_when_email_changes(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'google-subject-123', 'password' => null]);
        $this->fakeGoogle();
        $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->getJson('/api/me', ['Origin' => 'http://localhost:4200'])->assertOk()->assertJsonMissingPath('signup_method');
    }

    public function test_matching_password_account_is_not_silently_linked_or_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'google@example.com']);
        $this->fakeGoogle();
        $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/login?google_error=account_exists');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_authenticated_owner_can_explicitly_link_matching_verified_google_email(): void
    {
        $user = User::factory()->create(['email' => 'google@example.com']);
        $password = $user->password;
        $this->actingAs($user, 'web');
        $this->fakeGoogle();
        $this->completeGoogle($this->start('link'))->assertRedirect('http://localhost:4200/conta/perfil');
        $this->assertSame('google-subject-123', $user->fresh()->google_id);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->fakeGoogle();
        $this->completeGoogle($this->start())->assertRedirect('http://localhost:4200/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_linking_rejects_different_email_or_google_identity_already_owned_elsewhere(): void
    {
        $user = User::factory()->create(['email' => 'different@example.com']);
        $this->actingAs($user, 'web');
        $this->fakeGoogle();
        $this->completeGoogle($this->start('link'))->assertRedirect('http://localhost:4200/conta/perfil?google_error=link_conflict');
        $this->assertNull($user->fresh()->google_id);
        User::factory()->create(['google_id' => 'google-subject-123']);
        $user->update(['email' => 'google@example.com']);
        $this->fakeGoogle();
        $this->completeGoogle($this->start('link'))->assertRedirect('http://localhost:4200/conta/perfil?google_error=link_conflict');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_missing_expired_or_replayed_context_cannot_authenticate(): void
    {
        $this->fakeGoogle();
        $this->completeGoogle('missing')->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $state = $this->start();
        $this->travel(11)->minutes();
        $this->completeGoogle($state)->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $this->completeGoogle($state)->assertRedirect('http://localhost:4200/login?google_error=invalid_google');
        $this->assertCount(0, $this->history);
    }

    public function test_google_cannot_be_used_to_link_without_authenticated_session(): void
    {
        $this->postJson('/api/auth/google/start', ['intent' => 'link'])->assertUnauthorized();
    }

    public function test_google_is_gracefully_unavailable_until_credentials_are_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->postJson('/api/auth/google/start', ['intent' => 'login'])->assertStatus(503);
    }

    public function test_google_returns_to_account_only_for_allowed_paths(): void
    {
        $this->fakeGoogle();
        $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => 'https://evil.example/conta'])->assertUnprocessable();
        $response = $this->postJson('/api/auth/google/start', ['intent' => 'login', 'return_to' => '/conta'])->assertOk();
        parse_str(parse_url($response->json('redirect_url'), PHP_URL_QUERY), $query);
        $this->completeGoogle($query['state'])->assertRedirect('http://localhost:4200/conta');
    }
}
