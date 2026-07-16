<?php

declare(strict_types=1);

namespace App\Modules\Tables\Data;

use App\Modules\Tables\Exceptions\ReservationException;

/**
 * Trusted tenant/branch/user/device scope for the Reservation & Table
 * Management module. Mirrors the Sales SalesContext: scope is always taken
 * from the resolved AppContext, never from the request payload.
 */
final readonly class ReservationContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw ReservationException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
