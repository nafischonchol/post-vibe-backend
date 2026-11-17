<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \App\Contracts\AiServiceInterface::class,
            \App\Services\AI\OpenRouterService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum') ?? $request->user();
            $ip = $request->ip();

            if ($user) {
                return Limit::perMinute(400)->by('user_'.$user->id.'_'.$ip);
            }

            $visitorId = $request->header('X-Visitor-Id') ?? 'anonymous';

            return Limit::perMinute(200)->by('guest_'.$visitorId.'_'.$ip);
        });
    }
}
