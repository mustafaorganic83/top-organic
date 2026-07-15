<?php

namespace App\Modules\Identity\Contracts;

use App\Models\Device;
use App\Models\Tenant;

interface DeviceRepository
{
    public function find(Tenant $tenant, string $reference): ?Device;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Device;

    public function save(Device $device): Device;
}
