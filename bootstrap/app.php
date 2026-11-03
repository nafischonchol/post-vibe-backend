<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api/v1/tenant')
                ->name('tenant.public.')
                ->group(base_path('routes/Tenant/public.php'));

            Route::middleware(['api', 'auth:sanctum', 'tenant.check'])
                ->prefix('api/v1/tenant')
                ->name('tenant.')
                ->group(base_path('routes/Tenant/api.php'));

            Route::middleware('api')
                ->prefix('api/v1/admin')
                ->name('admin.')
                ->group(base_path('routes/Admin/api.php'));

            Route::middleware('api')
                ->prefix('api/v1/webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->api(append: [
            ThrottleRequests::class.':api',
        ]);
        $middleware->alias([
            'admin.check' => \App\Http\Middleware\VerifyAdminToken::class,
            'tenant.check' => \App\Http\Middleware\VerifyTenantToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return responseError('Resource not found.', 404);
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return responseError('Resource not found.', 404);
        });
    })->create();
