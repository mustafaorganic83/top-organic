<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Auth\PublicIdUserProvider;
use App\Modules\Identity\Contracts\DeviceRepository;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\RoleRepository;
use App\Modules\Identity\Contracts\SecurityEventRepository;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Repositories\EloquentDeviceRepository;
use App\Modules\Identity\Repositories\EloquentIdentityRepository;
use App\Modules\Identity\Repositories\EloquentRoleRepository;
use App\Modules\Identity\Repositories\EloquentSecurityEventRepository;
use App\Modules\Identity\Repositories\EloquentSecurityPolicyRepository;
use App\Modules\Identity\Repositories\EloquentSessionRepository;
use App\Modules\Identity\Services\JwtAccessTokenService;
use App\Modules\Identity\Services\MfaChallengeService;
use App\Modules\Identity\Services\OpaqueTokenFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        IdentityRepository::class => EloquentIdentityRepository::class,
        DeviceRepository::class => EloquentDeviceRepository::class,
        SessionRepository::class => EloquentSessionRepository::class,
        SecurityPolicyRepository::class => EloquentSecurityPolicyRepository::class,
        RoleRepository::class => EloquentRoleRepository::class,
        SecurityEventRepository::class => EloquentSecurityEventRepository::class,
    ];

    public function register(): void
    {
        $this->app->singleton(MfaChallengeService::class, function ($app): MfaChallengeService {
            $verifiers = array_map(
                fn (string $class) => $app->make($class),
                config('identity.mfa.verifiers', []),
            );

            return new MfaChallengeService($app->make(OpaqueTokenFactory::class), $verifiers);
        });
    }

    public function boot(): void
    {
        Auth::provider('jwt-public-id', fn ($app, array $config) => new PublicIdUserProvider(
            $app['hash'],
            $config['model'],
            $app->make(JwtAccessTokenService::class),
            $app['request'],
        ));

        RateLimiter::for('identity-auth', function (Request $request): Limit {
            $identity = implode('|', [
                $request->ip(),
                mb_strtolower((string) $request->input('tenant_slug')),
                mb_strtolower((string) $request->input('identifier')),
            ]);

            return Limit::perMinute(10)->by(hash('sha256', $identity));
        });
        RateLimiter::for('identity-device-registration', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
