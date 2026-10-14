<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            if (config('app.debug') || ! str_starts_with((string) config('app.url'), 'https://')
                || ! str_starts_with((string) config('feitoo.auth.frontend_url'), 'https://')
                || in_array('*', config('cors.allowed_origins'), true)
                || config('cors.allowed_origins') !== [config('feitoo.auth.frontend_url')]) {
                throw new \RuntimeException('Invalid production HTTPS, debug or CORS configuration.');
            }
            URL::forceScheme('https');
        }

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('subscription-status', fn (Request $request) => Limit::perMinute(60)->by('user:'.$request->user()->id));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('account:'.hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())),
        ]);
        RateLimiter::for('processing-create', fn (Request $request) => Limit::perMinute(config('feitoo.rate_limits.create_per_minute'))->by($request->ip()));
        RateLimiter::for('processing-upload', fn (Request $request) => Limit::perMinute(config('feitoo.rate_limits.upload_per_minute'))->by($request->ip()));
        RateLimiter::for('processing-access', fn (Request $request) => Limit::perMinute(config('feitoo.rate_limits.access_per_minute'))->by($request->ip()));
    }
}
