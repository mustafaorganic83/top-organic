<?php

use App\Http\Middleware\ResolveContext;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Locale resolution (Arabic primary, English fallback) runs on every
        // request. Tenant/branch context resolution runs on API requests,
        // after Sanctum has authenticated the user (architecture docs 02/03).
        $middleware->append(SetLocale::class);
        $middleware->api(append: [ResolveContext::class]);

        $middleware->alias([
            'resolve.context' => ResolveContext::class,
            'set.locale' => SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
