<?php

declare(strict_types=1);

namespace App\Modules\HR\Data;

use App\Modules\HR\Exceptions\HrException;

/**
 * Trusted tenant/branch/user/device scope for the HR module. Mirrors
 * ProcurementContext: scope always comes from the resolved AppContext, never
 * from the request payload. All HR state (employees, attendance, leave,
 * payroll) is branch-scoped, so the branch is always carried.
 */
final readonly class HrContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw HrException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
