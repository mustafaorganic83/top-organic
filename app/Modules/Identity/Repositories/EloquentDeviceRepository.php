<?php

namespace App\Modules\Identity\Repositories;

use App\Models\Device;
use App\Models\Tenant;
use App\Modules\Identity\Contracts\DeviceRepository;
use Illuminate\Database\Eloquent\Builder;

class EloquentDeviceRepository implements DeviceRepository
{
    public function find(Tenant $tenant, string $reference): ?Device
    {
        return Device::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where(fn (Builder $query) => $query
                ->where('id', $reference)->orWhere('code', $reference))
            ->first();
    }

    public function create(array $attributes): Device
    {
        return Device::withoutGlobalScopes()->create($attributes);
    }

    public function save(Device $device): Device
    {
        $device->save();

        return $device->refresh();
    }
}
