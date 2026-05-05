<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Per-API-key rate limit on transfer initiation. Bucket is the hashed
        // API key set by ApiKeyAuth middleware; falls back to the client IP
        // (defence in depth — should not normally be reached because the
        // throttle middleware is mounted after ApiKeyAuth).
        RateLimiter::for('transfers', function (Request $request): Limit {
            $bucket = (string) $request->attributes->get('api_key_hash', $request->ip());
            $perMinute = (int) config('app.transfer_rate_limit', 60);

            return Limit::perMinute($perMinute)->by($bucket);
        });
    }
}
