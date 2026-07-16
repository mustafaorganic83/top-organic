<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfflineLoginGrant extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'user_id', 'device_id', 'grant_token_hash',
        'permission_snapshot', 'password_version', 'security_version',
        'authorization_version', 'issued_at', 'expires_at', 'last_used_at',
        'revoked_at', 'revocation_reason',
    ];

    protected $hidden = ['grant_token_hash'];

    protected function casts(): array
    {
        return [
            'permission_snapshot' => 'array',
            'password_version' => 'integer',
            'security_version' => 'integer',
            'authorization_version' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(OfflineLoginReceipt::class);
    }
}
