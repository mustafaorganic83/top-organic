<?php

namespace App\Modules\Identity\Services;

use App\Models\Device;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Data\IssuedToken;
use App\Modules\Identity\Exceptions\IdentityException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RememberedDeviceService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly OpaqueTokenFactory $tokens,
    ) {}

    public function issue(User $user, Device $device, TenantSecurityPolicy $policy): IssuedToken
    {
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->find($policy->getKey()) ?? $policy;
        if (! $policy->allow_remembered_devices || $device->status !== 'authorized'
            || $device->tenant_id !== $user->tenant_id || $device->revoked_at !== null) {
            throw new IdentityException('REMEMBER_DEVICE_NOT_ALLOWED', 403, 'This device cannot be remembered.');
        }

        $value = $this->tokens->make();
        $expiresAt = CarbonImmutable::now()->addDays($policy->remember_device_days);
        $record = $this->sessions->upsertRemembered([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'token_hash' => $this->tokens->hash($value),
            'last_used_at' => now(),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);

        return new IssuedToken($value, $expiresAt, $record->getKey());
    }

    public function verify(User $user, Device $device, string $value): bool
    {
        return DB::transaction(function () use ($user, $device, $value): bool {
            $record = $this->sessions->lockRemembered($this->tokens->hash($value));
            $valid = $record !== null && $record->tenant_id === $user->tenant_id
                && $record->user_id === $user->getKey() && $record->device_id === $device->getKey()
                && $record->revoked_at === null && $record->expires_at->isFuture()
                && $device->status === 'authorized' && $device->revoked_at === null;
            if ($valid) {
                $record->forceFill(['last_used_at' => now()])->save();
            }

            return $valid;
        });
    }

    public function revoke(User $user, Device $device): void
    {
        $record = $user->rememberedDevices()->where('device_id', $device->getKey())->first();
        $record?->forceFill(['revoked_at' => now()])->save();
    }
}
