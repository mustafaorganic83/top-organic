<?php

use App\Http\Middleware\AuthenticatedContext;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePosDevice;
use App\Http\Middleware\ResolveContext;
use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetLocale::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/login');

        $middleware->alias([
            'resolve.context' => ResolveContext::class,
            'identity.context' => AuthenticatedContext::class,
            'permission' => RequirePermission::class,
            'pos.device' => RequirePosDevice::class,
            'set.locale' => SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $jsonError = static function (Request $request, array $error, int $status) {
            $requestId = $request->attributes->get('request_id');
            if (! is_string($requestId)) {
                $requestId = (string) Str::uuid();
                $request->attributes->set('request_id', $requestId);
            }

            return response()->json(['error' => $error, 'request_id' => $requestId], $status)
                ->header('X-Request-ID', $requestId);
        };

        $exceptions->render(function (SalesException $exception, Request $request) use ($jsonError) {
            $error = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
            if ($exception->context !== []) {
                $error['details'] = $exception->context;
            }

            return $jsonError($request, $error, $exception->status);
        });

        $exceptions->render(function (IdentityException $exception, Request $request) use ($jsonError) {
            if (! $request->expectsJson() && ! $request->is('api/*') && ! $request->is('login', 'logout')) {
                return null;
            }

            $error = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
            if ($exception->context !== []) {
                $error['details'] = $exception->context;
            }

            return $jsonError($request, $error, $exception->status);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($jsonError) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $jsonError($request, [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication is required.',
            ], 401);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($jsonError) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $jsonError($request, [
                'code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'fields' => $exception->errors(),
            ], 422);
        });
    })->create();
