<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Data;

use App\Modules\Kitchen\Exceptions\KitchenException;

/**
 * Trusted tenant/branch/user/device scope for the Kitchen Management module.
 * Mirrors the Sales SalesContext: scope always comes from the resolved
 * AppContext, never from the request payload.
 */
final readonly class KitchenContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw KitchenException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
