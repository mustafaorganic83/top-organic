<?php

namespace App\Modules\Identity\Repositories;

use App\Models\AuditLog;
use App\Models\OfflineLoginGrant;
use App\Models\OfflineLoginReceipt;
use App\Modules\Identity\Contracts\SecurityEventRepository;

class EloquentSecurityEventRepository implements SecurityEventRepository
{
    public function latestAudit(string $scopeKey): ?AuditLog
    {
        return AuditLog::withoutGlobalScopes()->where('scope_key', $scopeKey)
            ->orderByDesc('sequence')->lockForUpdate()->first();
    }

    public function createAudit(array $attributes): AuditLog
    {
        return AuditLog::withoutGlobalScopes()->create($attributes);
    }

    public function createOfflineGrant(array $attributes): OfflineLoginGrant
    {
        $grant = new OfflineLoginGrant;
        $grant->forceFill($attributes)->save();

        return $grant;
    }

    public function lockOfflineGrant(string $hash): ?OfflineLoginGrant
    {
        return OfflineLoginGrant::withoutGlobalScopes()->with(['user', 'device'])
            ->where('grant_token_hash', $hash)->lockForUpdate()->first();
    }

    public function firstOrCreateReceipt(array $attributes): OfflineLoginReceipt
    {
        return OfflineLoginReceipt::withoutGlobalScopes()->firstOrCreate([
            'offline_login_grant_id' => $attributes['offline_login_grant_id'],
            'client_receipt_id' => $attributes['client_receipt_id'],
        ], $attributes);
    }
}
