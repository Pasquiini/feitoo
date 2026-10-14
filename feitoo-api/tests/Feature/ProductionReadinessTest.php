<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    private function productionConfig(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://api.feitoo.com.br',
            'feitoo.auth.frontend_url' => 'https://feitoo.com.br',
            'cors.allowed_origins' => ['https://feitoo.com.br'],
        ]);
    }

    public function test_production_boot_accepts_https_and_exact_credentialed_origin(): void
    {
        $this->productionConfig();
        (new AppServiceProvider($this->app))->boot();
        $this->getJson('/api/health', ['Origin' => 'https://feitoo.com.br'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://feitoo.com.br')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->getJson('/api/health', ['Origin' => 'http://localhost:4200'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://feitoo.com.br');
    }

    public function test_production_rejects_debug(): void
    {
        $this->productionConfig();
        config(['app.debug' => true]);
        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_rejects_wildcard_credentials(): void
    {
        $this->productionConfig();
        config(['cors.allowed_origins' => ['*']]);
        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_rejects_http_api(): void
    {
        $this->productionConfig();
        config(['app.url' => 'http://api.feitoo.com.br']);
        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }
}
