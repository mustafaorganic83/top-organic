<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\EmployeeHistory;
use App\Modules\HR\Data\HrContext;
use Illuminate\Database\Eloquent\Collection;

/** Read-only access to the employee history trail. */
final class HistoryService
{
    /** @return Collection<int, EmployeeHistory> */
    public function forEmployee(HrContext $context, string $employeeId, ?string $entityType = null): Collection
    {
        return EmployeeHistory::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('entity_id', $employeeId)
            ->when($entityType, fn ($q) => $q->where('entity_type', $entityType))
            ->orderByDesc('occurred_at')
            ->get();
    }
}
