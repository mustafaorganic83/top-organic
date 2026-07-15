<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Services\PermissionResolver;
use App\Support\Context\AppContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AppContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $user = $request->user('api');
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $current = $this->permissions->resolve($user->fresh(), $this->context->branchId());
        if (collect($required)->contains(fn (string $permission) => ! $current->contains($permission))) {
            throw new IdentityException('PERMISSION_DENIED', 403, 'The required permission has not been granted.');
        }

        $request->attributes->set('current_permissions', $current);

        return $next($request);
    }
}
