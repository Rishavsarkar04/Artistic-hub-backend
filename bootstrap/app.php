<?php

use App\Http\Middleware\EnsureTokenIsFresh;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Only loads the files; each sets its own prefix, version and middleware.
            // See docs/backend-architecture.md, section 2.1.
            Route::group([], base_path('routes/api.php'));
            Route::group([], base_path('routes/api/customer.php'));
            Route::group([], base_path('routes/api/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // There is no login page: API guests get a 401 JSON response instead of a redirect.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'scope' => CheckToken::class,
            'active' => EnsureUserIsActive::class,
            'token.fresh' => EnsureTokenIsFresh::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
