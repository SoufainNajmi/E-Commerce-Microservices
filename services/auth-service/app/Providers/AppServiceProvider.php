<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel merges framework defaults; remove unused database drivers.
        config(['database.connections' => ['pgsql' => config('database.connections.pgsql')]]);
    }

    public function boot(): void
    {
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(20)->by($request->ip()), Limit::perMinute(5)->by(hash('sha256', mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()))]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
