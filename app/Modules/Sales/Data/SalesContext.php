<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

use App\Modules\Sales\Exceptions\SalesException;

final readonly class SalesContext
{
    public function __construct(
        public string $tenantId,
        public string $branchId,
        public int $userId,
        public ?string $deviceId,
    ) {
        if ($tenantId === '' || $branchId === '' || $userId < 1) {
            throw SalesException::invalid('Tenant, branch, and user context are required.');
        }
    }
}
