<?php

namespace App\Modules\Identity\Services;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Identity\Contracts\DeviceRepository;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;

class DeviceAuthorizationService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly SessionRepository $sessions,
        private readonly SecurityAuditService $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function register(Tenant $tenant, array $attributes): Device
    {
        $device = $this->devices->create(array_merge($attributes, [
            'tenant_id' => $tenant->getKey(),
            'status' => 'pending',
            'key_fingerprint' => mb_strtolower($attributes['key_fingerprint']),
            'authorization_requested_at' => now(),
        ]));
        $this->audit->record($tenant->getKey(), $device->branch_id, 'security', 'device.registered', [
            'target_type' => Device::class, 'target_id' => $device->getKey(),
        ]);

        return $device;
    }

    public function approve(Device $device, User $actor): Device
    {
        $this->assertSameTenant($device, $actor);
        if ($device->status === 'revoked') {
            throw new IdentityException('DEVICE_REVOKED', 409, 'A revoked device must be registered again.');
        }

        $device->forceFill([
            'status' => 'authorized', 'authorized_by' => $actor->getKey(),
            'authorized_at' => now(), 'revoked_at' => null, 'revocation_reason' => null,
        ]);
        $device = $this->devices->save($device);
        $this->audit->record($device->tenant_id, $device->branch_id, 'security', 'device.approved', [
            'actor_id' => $actor->getKey(), 'target_type' => Device::class, 'target_id' => $device->getKey(),
        ]);

        return $device;
    }

    public function revoke(Device $device, User $actor, string $reason): Device
    {
        $this->assertSameTenant($device, $actor);

        return DB::transaction(function () use ($device, $actor, $reason): Device {
            $device->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => $reason]);
            $device = $this->devices->save($device);
            $this->sessions->revokeDeviceSessions($device->getKey(), 'device_revoked');
            $this->audit->record($device->tenant_id, $device->branch_id, 'security', 'device.revoked', [
                'actor_id' => $actor->getKey(), 'target_type' => Device::class,
                'target_id' => $device->getKey(), 'reason' => $reason,
            ]);

            return $device;
        });
    }

    private function assertSameTenant(Device $device, User $actor): void
    {
        if ($device->tenant_id !== $actor->tenant_id) {
            throw new IdentityException('TENANT_SCOPE_VIOLATION', 403, 'The device is outside the actor tenant.');
        }
    }
}
