<?php

namespace App\Modules\Identity\Contracts;

use App\Models\AuditLog;
use App\Models\OfflineLoginGrant;
use App\Models\OfflineLoginReceipt;

interface SecurityEventRepository
{
    public function latestAudit(string $scopeKey): ?AuditLog;

    /** @param array<string, mixed> $attributes */
    public function createAudit(array $attributes): AuditLog;

    /** @param array<string, mixed> $attributes */
    public function createOfflineGrant(array $attributes): OfflineLoginGrant;

    public function lockOfflineGrant(string $hash): ?OfflineLoginGrant;

    /** @param array<string, mixed> $attributes */
    public function firstOrCreateReceipt(array $attributes): OfflineLoginReceipt;
}
