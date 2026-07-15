<?php

namespace App\Modules\Identity\Services;

use App\Models\PasswordHistory;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordPolicyService
{
    public function assertValid(string $password, TenantSecurityPolicy $policy, ?User $user = null): void
    {
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->find($policy->getKey()) ?? $policy;
        $valid = mb_strlen($password) >= $policy->password_min_length
            && (! config('identity.password.require_letters', true) || preg_match('/[A-Za-z]/', $password))
            && (! config('identity.password.require_numbers', true) || preg_match('/[0-9]/', $password))
            && (! config('identity.password.require_symbols', false) || preg_match('/[^A-Za-z0-9]/', $password));

        if (! $valid) {
            throw new IdentityException('PASSWORD_POLICY_VIOLATION', 422, 'The password does not meet the security policy.');
        }

        if ($user !== null) {
            $this->assertNotReused($user, $password, $policy->password_history_count);
        }
    }

    public function change(User $user, string $password, TenantSecurityPolicy $policy): User
    {
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->find($policy->getKey()) ?? $policy;
        $this->assertValid($password, $policy, $user);

        return DB::transaction(function () use ($user, $password, $policy): User {
            $locked = User::withoutGlobalScopes()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            PasswordHistory::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id,
                'user_id' => $locked->getKey(),
                'password_hash' => $locked->password,
            ]);
            $locked->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'password_version' => $locked->password_version + 1,
                'security_version' => $locked->security_version + 1,
            ])->save();

            $keep = max(0, $policy->password_history_count);
            $deleteIds = PasswordHistory::withoutGlobalScopes()->where('user_id', $locked->getKey())
                ->latest('created_at')->skip($keep)->take(PHP_INT_MAX)->pluck('id');
            PasswordHistory::withoutGlobalScopes()->whereIn('id', $deleteIds)->delete();

            return $locked->refresh();
        });
    }

    private function assertNotReused(User $user, string $password, int $historyCount): void
    {
        $hashes = PasswordHistory::withoutGlobalScopes()->where('user_id', $user->getKey())
            ->latest('created_at')->limit($historyCount)->pluck('password_hash')->prepend($user->password);

        if ($hashes->contains(fn (string $hash): bool => Hash::check($password, $hash))) {
            throw new IdentityException('PASSWORD_REUSED', 422, 'A recently used password cannot be reused.');
        }
    }
}
