<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\User;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Collection;

class SessionService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly SecurityAuditService $audit,
    ) {}

    /** @return Collection<int, AuthSession> */
    public function list(User $user): Collection
    {
        return $this->sessions->activeForUser($user->getKey());
    }

    public function revoke(User $user, string $sessionId, string $reason = 'user_revoked'): void
    {
        $session = $this->sessions->findSession($sessionId);
        if ($session === null || $session->user_id !== $user->getKey()) {
            throw new IdentityException('SESSION_NOT_FOUND', 404, 'The session was not found.');
        }

        $this->sessions->revokeSession($session, $reason);
        $this->audit->record($user->tenant_id, $session->branch_id, 'security', 'session.revoked', [
            'actor_id' => $user->getKey(), 'target_type' => AuthSession::class,
            'target_id' => $session->getKey(), 'reason' => $reason,
        ]);
    }

    public function logoutAll(User $user, ?string $exceptSessionId = null): int
    {
        $count = $this->sessions->revokeUserSessions($user->getKey(), 'logout_all', $exceptSessionId);
        $this->audit->record($user->tenant_id, null, 'security', 'session.logout_all', [
            'actor_id' => $user->getKey(), 'metadata' => ['revoked_count' => $count],
        ]);

        return $count;
    }
}
