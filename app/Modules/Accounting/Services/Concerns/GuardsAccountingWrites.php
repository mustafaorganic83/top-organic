<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services\Concerns;

use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write-guard helpers for Accounting services: optimistic-lock
 * assertion, per-tenant uniqueness guard.
 */
trait GuardsAccountingWrites
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw AccountingException::conflict(
                AccountingException::STALE_VERSION,
                'The record was modified by another operation.',
            );
        }
    }

    /**
     * Assert a value is unique within the tenant for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertTenantUnique(
        string $model,
        AccountingContext $context,
        string $column,
        string $value,
        ?string $ignoreId,
    ): void {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw AccountingException::conflict(
                AccountingException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }

    /** Assert account allows direct posting (is a leaf node). */
    protected function assertPostable(\App\Models\Account $account): void
    {
        if (! $account->allow_direct_posting || ! $account->is_leaf) {
            throw AccountingException::invalid(
                "Account [{$account->code}] does not allow direct posting.",
            );
        }
    }
}
