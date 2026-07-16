<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services\Concerns;

use App\Modules\Menu\Exceptions\MenuException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write helpers for the Menu & Recipe services: optimistic-lock version
 * assertion and a small tenant-uniqueness guard. Kept minimal — the recipe
 * versioning trail is the immutable recipe_versions history itself, so no
 * separate audit table is needed here.
 */
trait GuardsMenuWrites
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw MenuException::conflict(
                MenuException::STALE_VERSION,
                'The record was changed by another operation.',
            );
        }
    }

    /**
     * Assert a code is unique within the tenant for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertTenantCodeUnique(string $model, string $tenantId, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }
}
