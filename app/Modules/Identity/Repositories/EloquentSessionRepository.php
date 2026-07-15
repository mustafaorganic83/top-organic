<?php

namespace App\Modules\Identity\Repositories;

use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\RememberedDevice;
use App\Modules\Identity\Contracts\SessionRepository;
use Illuminate\Support\Collection;

class EloquentSessionRepository implements SessionRepository
{
    public function createSession(array $attributes): AuthSession
    {
        return AuthSession::withoutGlobalScopes()->create($attributes);
    }

    public function findSession(string $id, bool $lock = false): ?AuthSession
    {
        $query = AuthSession::withoutGlobalScopes()->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function createRefreshToken(array $attributes): RefreshToken
    {
        return RefreshToken::query()->create($attributes);
    }

    public function lockRefreshToken(string $hash): ?RefreshToken
    {
        return RefreshToken::query()->with('authSession.user')->where('token_hash', $hash)->lockForUpdate()->first();
    }

    public function findRefreshToken(string $id): ?RefreshToken
    {
        return RefreshToken::query()->with('authSession.user')->find($id);
    }

    public function saveRefreshToken(RefreshToken $token): void
    {
        $token->save();
    }

    public function revokeRefreshFamily(string $familyId): int
    {
        return RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeSession(AuthSession $session, string $reason): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now(), 'revocation_reason' => $reason])->save();
        }

        RefreshToken::query()->where('auth_session_id', $session->getKey())
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeUserSessions(int $userId, string $reason, ?string $exceptId = null): int
    {
        $query = AuthSession::withoutGlobalScopes()->where('user_id', $userId)->whereNull('revoked_at');
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $ids = (clone $query)->pluck('id');
        RefreshToken::query()->whereIn('auth_session_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return $query->update(['revoked_at' => now(), 'revocation_reason' => $reason]);
    }

    public function revokeDeviceSessions(string $deviceId, string $reason): int
    {
        $query = AuthSession::withoutGlobalScopes()->where('device_id', $deviceId)->whereNull('revoked_at');
        $ids = (clone $query)->pluck('id');
        RefreshToken::query()->whereIn('auth_session_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return $query->update(['revoked_at' => now(), 'revocation_reason' => $reason]);
    }

    public function activeForUser(int $userId): Collection
    {
        return AuthSession::withoutGlobalScopes()->where('user_id', $userId)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get();
    }

    public function upsertRemembered(array $attributes): RememberedDevice
    {
        $keys = collect($attributes)->only(['tenant_id', 'user_id', 'device_id'])->all();

        return RememberedDevice::withoutGlobalScopes()->updateOrCreate($keys, $attributes);
    }

    public function lockRemembered(string $hash): ?RememberedDevice
    {
        return RememberedDevice::withoutGlobalScopes()->where('token_hash', $hash)->lockForUpdate()->first();
    }
}
