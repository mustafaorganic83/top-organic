<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Geofence;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Branch geofence management for GPS attendance validation. */
final class GeofenceService
{
    use GuardsHrWrites;

    /** @return Collection<int, Geofence> */
    public function list(HrContext $context): Collection
    {
        return Geofence::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->orderBy('name')
            ->get();
    }

    public function find(HrContext $context, string $id): Geofence
    {
        return Geofence::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Geofence not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): Geofence
    {
        return DB::transaction(function () use ($context, $data): Geofence {
            $fence = Geofence::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'name' => $data['name'],
                'center_lat' => $data['center_lat'],
                'center_lng' => $data['center_lng'],
                'radius_meters' => (int) ($data['radius_meters'] ?? 100),
                'is_active' => true,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'geofence', $fence->id, 'created');

            return $fence;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(HrContext $context, string $id, int $version, array $data): Geofence
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Geofence {
            $fence = Geofence::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Geofence not found.');
            $this->assertVersion($fence->lock_version, $version);
            $fence->fill(array_intersect_key($data, array_flip([
                'name', 'center_lat', 'center_lng', 'radius_meters', 'is_active',
            ])));
            $fence->lock_version++;
            $fence->save();

            return $fence->refresh();
        }, 3);
    }
}
