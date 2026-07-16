<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services;

use App\Models\KdsStation;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Exceptions\KitchenException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages kitchen stations and their screen/printer wiring. A station is a
 * physical prep area with its own KDS screen; the station type distinguishes
 * kitchen line, bar, grill, dessert, etc. Reuses the Sales kds_stations table
 * so tickets dispatched from POS land on the same stations the kitchen board
 * renders.
 */
final class StationService
{
    /** @return Collection<int, KdsStation> */
    public function list(KitchenContext $context, bool $activeOnly): Collection
    {
        $query = KdsStation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId);
        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(KitchenContext $context, array $data): KdsStation
    {
        $this->assertCodeUnique($context, (string) $data['code'], null);

        return KdsStation::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'device_id' => $data['device_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'station_type' => $data['station_type'] ?? 'kitchen',
            'sla_seconds' => $data['sla_seconds'] ?? null,
            'default_prep_seconds' => $data['default_prep_seconds'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'screen_config' => $data['screen_config'] ?? null,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(KitchenContext $context, string $id, int $expectedVersion, array $data): KdsStation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion, $data): KdsStation {
            $station = $this->findForUpdate($context, $id);
            if ($station->lock_version !== $expectedVersion) {
                throw KitchenException::conflict(KitchenException::STALE_VERSION,
                    'The station was changed by another operation.');
            }
            if (array_key_exists('code', $data)) {
                $this->assertCodeUnique($context, (string) $data['code'], $id);
            }
            $station->fill(array_intersect_key($data, array_flip([
                'device_id', 'code', 'name', 'station_type', 'sla_seconds',
                'default_prep_seconds', 'sort_order', 'screen_config', 'status',
            ])));
            $station->lock_version = $station->lock_version + 1;
            $station->save();

            return $station->refresh();
        }, 3);
    }

    public function find(KitchenContext $context, string $id): KdsStation
    {
        return KdsStation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)->first()
            ?? throw KitchenException::notFound('The kitchen station was not found.');
    }

    private function findForUpdate(KitchenContext $context, string $id): KdsStation
    {
        return KdsStation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)->lockForUpdate()->first()
            ?? throw KitchenException::notFound('The kitchen station was not found.');
    }

    private function assertCodeUnique(KitchenContext $context, string $code, ?string $ignoreId): void
    {
        $exists = KdsStation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw KitchenException::conflict(KitchenException::IN_USE,
                'A station with this code already exists in this branch.');
        }
    }
}
