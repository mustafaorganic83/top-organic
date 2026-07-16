<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\LeaveRequest;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Leave request lifecycle: draft → submitted → approved / rejected → cancelled. */
final class LeaveService
{
    use GuardsHrWrites;

    /** @return Collection<int, LeaveRequest> */
    public function list(HrContext $context, ?string $employeeId = null, ?string $status = null): Collection
    {
        return LeaveRequest::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('start_date')
            ->get();
    }

    public function find(HrContext $context, string $id): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Leave request not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($context, $data): LeaveRequest {
            $leave = LeaveRequest::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $data['employee_id'],
                'type' => $data['leave_type'] ?? 'annual',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'] ?? 1,
                'reason' => $data['reason'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now(),
                'lock_version' => 0,
            ]);
            $this->audit($context, 'leave', $leave->id, 'submitted');

            return $leave;
        }, 3);
    }

    public function approve(HrContext $context, string $id, int $version): LeaveRequest
    {
        return DB::transaction(function () use ($context, $id, $version): LeaveRequest {
            $leave = LeaveRequest::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Leave request not found.');
            $this->assertVersion($leave->lock_version, $version);
            if ($leave->status !== 'submitted') {
                throw HrException::invalidTransition($leave->status, 'approved');
            }
            $leave->status = 'approved';
            $leave->approved_by = (string) $context->userId; // FK to users, must be a valid ULID
            $leave->approved_at = now();
            $leave->lock_version++;
            $leave->save();
            $this->audit($context, 'leave', $leave->id, 'approved');

            return $leave->refresh();
        }, 3);
    }

    public function reject(HrContext $context, string $id, int $version, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($context, $id, $version, $reason): LeaveRequest {
            $leave = LeaveRequest::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Leave request not found.');
            $this->assertVersion($leave->lock_version, $version);
            if ($leave->status !== 'submitted') {
                throw HrException::invalidTransition($leave->status, 'rejected');
            }
            $leave->status = 'rejected';
            $leave->rejection_reason = $reason;
            $leave->lock_version++;
            $leave->save();
            $this->audit($context, 'leave', $leave->id, 'rejected');

            return $leave->refresh();
        }, 3);
    }
}
