<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBranchRole extends Model
{
    use BelongsToBranch, BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'user_id', 'role_id', 'granted_by',
        'revoked_by', 'effective_at', 'expires_at', 'revoked_at', 'revocation_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            if ($grant->revoked_at === null) {
                $grant->active_key = implode(':', [
                    $grant->tenant_id, $grant->branch_id, $grant->user_id, $grant->role_id,
                ]);
            }
        });

        static::updating(function (self $grant): void {
            if ($grant->isDirty('revoked_at')) {
                $grant->active_key = $grant->revoked_at === null
                    ? implode(':', [$grant->tenant_id, $grant->branch_id, $grant->user_id, $grant->role_id])
                    : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
