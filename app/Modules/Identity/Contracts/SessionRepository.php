<?php

namespace App\Modules\Identity\Contracts;

use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\RememberedDevice;
use Illuminate\Support\Collection;

interface SessionRepository
{
    /** @param array<string, mixed> $attributes */
    public function createSession(array $attributes): AuthSession;

    public function findSession(string $id, bool $lock = false): ?AuthSession;

    /** @param array<string, mixed> $attributes */
    public function createRefreshToken(array $attributes): RefreshToken;

    public function lockRefreshToken(string $hash): ?RefreshToken;

    public function findRefreshToken(string $id): ?RefreshToken;

    public function saveRefreshToken(RefreshToken $token): void;

    public function revokeRefreshFamily(string $familyId): int;

    public function revokeSession(AuthSession $session, string $reason): void;

    public function revokeUserSessions(int $userId, string $reason, ?string $exceptId = null): int;

    public function revokeDeviceSessions(string $deviceId, string $reason): int;

    /** @return Collection<int, AuthSession> */
    public function activeForUser(int $userId): Collection;

    /** @param array<string, mixed> $attributes */
    public function upsertRemembered(array $attributes): RememberedDevice;

    public function lockRemembered(string $hash): ?RememberedDevice;
}
