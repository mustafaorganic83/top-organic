<?php

namespace App\Modules\Identity\Services;

use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;

class AccountLockoutService
{
    public function assertAvailable(User $user): void
    {
        $locked = DB::transaction(function () use ($user): User {
            $locked = User::withoutGlobalScopes()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->lock_expires_at?->isPast()) {
                $locked->forceFill(['failed_login_attempts' => 0, 'locked_at' => null, 'lock_expires_at' => null])->save();
            }

            return $locked;
        });

        if ($locked->locked_at !== null && ($locked->lock_expires_at === null || $locked->lock_expires_at->isFuture())) {
            throw new IdentityException('ACCOUNT_LOCKED', 423, 'The account is temporarily locked.', [
                'retry_at' => $locked->lock_expires_at?->toIso8601String(),
            ]);
        }
    }

    public function recordFailure(User $user, TenantSecurityPolicy $policy): bool
    {
        return DB::transaction(function () use ($user, $policy): bool {
            $locked = User::withoutGlobalScopes()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $attempts = $locked->lock_expires_at?->isPast() ? 1 : $locked->failed_login_attempts + 1;
            $attributes = ['failed_login_attempts' => $attempts];
            if ($attempts >= $policy->max_failed_login_attempts) {
                $attributes += ['locked_at' => now(), 'lock_expires_at' => now()->addMinutes($policy->lockout_minutes)];
            }
            $locked->forceFill($attributes)->save();

            return $attempts >= $policy->max_failed_login_attempts;
        });
    }

    public function recordSuccess(User $user): void
    {
        User::withoutGlobalScopes()->whereKey($user->getKey())->update([
            'failed_login_attempts' => 0,
            'locked_at' => null,
            'lock_expires_at' => null,
            'last_login_at' => now(),
        ]);
    }
}
