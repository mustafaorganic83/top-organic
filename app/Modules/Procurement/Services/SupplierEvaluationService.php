<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\Supplier;
use App\Models\SupplierEvaluation;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages supplier performance evaluations. Each evaluation records scored
 * criteria (quality, delivery, price, compliance) and an overall score that
 * can be used to update the supplier's rating.
 */
final class SupplierEvaluationService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, SupplierEvaluation> */
    public function list(ProcurementContext $context, string $supplierId): Collection
    {
        return SupplierEvaluation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('evaluated_at')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, string $supplierId, array $data): SupplierEvaluation
    {
        return DB::transaction(function () use ($context, $supplierId, $data): SupplierEvaluation {
            Supplier::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($supplierId)
                ->firstOrFail();

            $eval = SupplierEvaluation::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'supplier_id' => $supplierId,
                'evaluator_id' => $context->userId,
                'criteria' => $data['criteria'] ?? null,
                'score' => $data['score'],
                'notes' => $data['notes'] ?? null,
                'evaluated_at' => $data['evaluated_at'] ?? now(),
                'lock_version' => 0,
            ]);

            // Update supplier's aggregate rating (rounded score).
            Supplier::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($supplierId)
                ->update(['rating' => (int) round((float) $data['score'])]);

            $this->audit($context, 'supplier_evaluation', $eval->id, 'created');

            return $eval;
        }, 3);
    }

    public function find(ProcurementContext $context, string $id): SupplierEvaluation
    {
        return SupplierEvaluation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->first()
            ?? throw ProcurementException::notFound('Supplier evaluation not found.');
    }
}
