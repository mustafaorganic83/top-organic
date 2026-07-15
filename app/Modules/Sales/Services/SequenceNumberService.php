<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Branch;
use App\Models\SalesSequence;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SequenceNumberService
{
    public function nextSequence(SalesContext $context, string $scope, DateTimeInterface|string $businessDate): int
    {
        $date = is_string($businessDate) ? $businessDate : $businessDate->format('Y-m-d');
        if (! preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw SalesException::invalid('A valid sequence scope and business date are required.');
        }
        $sequenceBranchId = $scope === 'change_log' ? null : $context->branchId;
        $scopeBranch = $sequenceBranchId ?? '_global';
        $now = now();
        DB::table('sales_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'tenant_id' => $context->tenantId, 'branch_id' => $sequenceBranchId,
            'scope_branch' => $scopeBranch, 'scope' => $scope, 'business_date' => $date,
            'next_value' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($context, $scopeBranch, $scope, $date): int {
            $sequence = SalesSequence::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('scope_branch', $scopeBranch)->where('scope', $scope)
                ->whereDate('business_date', $date)->lockForUpdate()->first();
            if ($sequence === null || $sequence->next_value === PHP_INT_MAX) {
                throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The numbering sequence is unavailable or exhausted.');
            }
            $value = $sequence->next_value;
            $sequence->next_value = $value + 1;
            $sequence->save();

            return $value;
        }, 3);
    }

    public function nextNumber(SalesContext $context, string $scope, DateTimeInterface|string $businessDate): string
    {
        $date = is_string($businessDate) ? $businessDate : $businessDate->format('Y-m-d');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($context->branchId)->first();
        if ($branch === null) {
            throw new SalesException(SalesException::SCOPE_VIOLATION, 403, 'The branch is outside the trusted tenant scope.');
        }
        $prefix = match ($scope) {
            'order' => 'ORD', 'invoice' => 'INV', 'shift' => 'SHF',
            default => strtoupper(substr($scope, 0, 3)),
        };
        $value = $this->nextSequence($context, $scope, $date);

        return sprintf('%s-%s-%s-%06d', $prefix, strtoupper($branch->code), str_replace('-', '', $date), $value);
    }
}
