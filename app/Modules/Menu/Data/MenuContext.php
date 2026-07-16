<?php

declare(strict_types=1);

namespace App\Modules\Menu\Data;

use App\Modules\Menu\Exceptions\MenuException;

/**
 * Trusted tenant/branch/user/device scope for the Menu & Recipe module.
 * Mirrors the Sales SalesContext and Kitchen KitchenContext: scope always
 * comes from the resolved AppContext, never from the request payload. Menu and
 * recipe definitions are tenant-wide; stock levels and consumption are
 * branch-scoped, which is why the branch is always carried too.
 */
final readonly class MenuContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw MenuException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
