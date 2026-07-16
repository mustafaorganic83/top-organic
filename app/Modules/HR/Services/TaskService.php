<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\HrTask;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** HR task management (open → in_progress → done → cancelled). */
final class TaskService
{
    use GuardsHrWrites;

    /** @return Collection<int, HrTask> */
    public function list(HrContext $context, ?string $employeeId = null, ?string $status = null): Collection
    {
        return HrTask::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('due_date')
            ->get();
    }

    public function find(HrContext $context, string $id): HrTask
    {
        return HrTask::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Task not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): HrTask
    {
        return DB::transaction(function () use ($context, $data): HrTask {
            $task = HrTask::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $data['employee_id'],
                'created_by' => $context->userId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'lock_version' => 0,
            ]);
            $this->audit($context, 'task', $task->id, 'created');

            return $task;
        }, 3);
    }

    public function complete(HrContext $context, string $id, int $version): HrTask
    {
        return DB::transaction(function () use ($context, $id, $version): HrTask {
            $task = HrTask::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Task not found.');
            $this->assertVersion($task->lock_version, $version);
            if (! in_array($task->status, ['open', 'in_progress'], true)) {
                throw HrException::invalidTransition($task->status, 'done');
            }
            $task->status = 'done';
            $task->completed_at = now();
            $task->lock_version++;
            $task->save();
            $this->audit($context, 'task', $task->id, 'completed');

            return $task->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(HrContext $context, string $id, int $version, array $data): HrTask
    {
        return DB::transaction(function () use ($context, $id, $version, $data): HrTask {
            $task = HrTask::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Task not found.');
            $this->assertVersion($task->lock_version, $version);
            $task->fill(array_intersect_key($data, array_flip([
                'title', 'description', 'due_date', 'priority', 'status',
            ])));
            $task->lock_version++;
            $task->save();

            return $task->refresh();
        }, 3);
    }
}
