<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'code', 'name', 'type', 'status', 'public_key',
        'key_fingerprint', 'app_version', 'os_version', 'authorized_by',
        'authorization_requested_at', 'authorized_at', 'revoked_at',
        'revocation_reason', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'authorization_requested_at' => 'datetime',
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function rememberedBy(): HasMany
    {
        return $this->hasMany(RememberedDevice::class);
    }

    public function offlineLoginGrants(): HasMany
    {
        return $this->hasMany(OfflineLoginGrant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function syncBatches(): HasMany
    {
        return $this->hasMany(SyncBatch::class);
    }
}
