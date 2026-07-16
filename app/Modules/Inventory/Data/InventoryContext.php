<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Exceptions\InventoryException;

/**
 * Trusted tenant/branch/user/device scope for the Inventory module. Mirrors
 * the Menu MenuContext and Kitchen KitchenContext: scope always comes from the
 * resolved AppContext, never from the request payload. All inventory state
 * (warehouses, batches, levels, transfers, counts) is branch-scoped, so the
 * branch is always carried.
 */
final readonly class InventoryContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw InventoryException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
