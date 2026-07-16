<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Data;

use App\Modules\Procurement\Exceptions\ProcurementException;

/**
 * Trusted tenant/branch/user/device scope for the Procurement module.
 * Mirrors InventoryContext: scope always comes from the resolved AppContext,
 * never from the request payload. All procurement state (RFQs, POs, receipts,
 * contracts) is branch-scoped, so the branch is always carried.
 */
final readonly class ProcurementContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw ProcurementException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
