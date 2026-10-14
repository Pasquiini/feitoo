<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_public_and_returns_the_service_identity(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'feitoo-api']);
    }

    public function test_development_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:4200']]);

        $this->withHeader('Origin', 'http://localhost:4200')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_preflight_is_handled_by_native_cors_middleware(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:4200']]);

        $this->withHeaders([
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Content-Type',
        ])->options('/api/health')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_unlisted_origin_does_not_receive_cors_permission(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:4200']]);

        $this->withHeader('Origin', 'https://unlisted.example')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }
}
