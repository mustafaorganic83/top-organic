<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire\Concerns;

use App\Models\User;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Support\Context\AppContext;

/**
 * Shared context + permission plumbing for the Menu Livewire components. Scope
 * comes from the resolved AppContext (set by the web.context middleware on the
 * first request and re-derived on Livewire updates), never from component
 * state, so a tampered payload cannot cross a tenant or branch boundary.
 */
trait ResolvesMenuContext
{
    /** Resolved once per request; never serialised into component state. */
    private ?MenuContext $resolvedMenuContext = null;

    protected function menuContext(): MenuContext
    {
        if ($this->resolvedMenuContext instanceof MenuContext) {
            return $this->resolvedMenuContext;
        }

        $app = app(AppContext::class);
        $user = auth('web')->user();

        if (! $user instanceof User) {
            throw new MenuException(MenuException::SCOPE_VIOLATION, 403,
                'A signed-in user is required.');
        }

        if ($app->tenantId() === null || $app->branchId() === null) {
            throw new MenuException(MenuException::SCOPE_VIOLATION, 403,
                'A trusted tenant and branch context is required.');
        }

        return $this->resolvedMenuContext = new MenuContext(
            $app->tenantId(), $app->branchId(), (int) $user->getKey(), $app->deviceId(),
        );
    }

    /** Abort the component action unless the signed-in user holds the permission. */
    protected function authorizeMenu(string $permission): void
    {
        $user = auth('web')->user();
        $branchId = app(AppContext::class)->branchId();

        if (! $user instanceof User || ! $user->hasPermissionTo($permission, $branchId)) {
            abort(403);
        }
    }
}
