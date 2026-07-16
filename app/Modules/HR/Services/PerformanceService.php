<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\PerformanceReview;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Performance review lifecycle: draft → submitted → acknowledged. */
final class PerformanceService
{
    use GuardsHrWrites;

    /** @return Collection<int, PerformanceReview> */
    public function list(HrContext $context, ?string $employeeId = null): Collection
    {
        return PerformanceReview::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('review_period_end')
            ->get();
    }

    public function find(HrContext $context, string $id): PerformanceReview
    {
        return PerformanceReview::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Performance review not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): PerformanceReview
    {
        return DB::transaction(function () use ($context, $data): PerformanceReview {
            $review = PerformanceReview::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $data['employee_id'],
                'reviewer_id' => $context->userId,
                'review_period_start' => $data['review_period_start'] ?? null,
                'review_period_end' => $data['review_period_end'] ?? null,
                'score' => $data['score'] ?? null,
                'rating' => $data['rating'] ?? null,
                'comments' => $data['comments'] ?? null,
                'status' => 'submitted',
                'lock_version' => 0,
            ]);
            $this->audit($context, 'performance', $review->id, 'created');

            return $review;
        }, 3);
    }

    public function acknowledge(HrContext $context, string $id, int $version): PerformanceReview
    {
        return DB::transaction(function () use ($context, $id, $version): PerformanceReview {
            $review = PerformanceReview::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Performance review not found.');
            $this->assertVersion($review->lock_version, $version);
            $review->status = 'acknowledged';
            $review->lock_version++;
            $review->save();
            $this->audit($context, 'performance', $review->id, 'acknowledged');

            return $review->refresh();
        }, 3);
    }
}
