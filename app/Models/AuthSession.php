<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthSession extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'user_id', 'device_id', 'session_key_hash',
        'authentication_method', 'mfa_completed', 'ip_address', 'user_agent',
        'password_version', 'security_version', 'authorization_version',
        'last_seen_at', 'expires_at', 'revoked_at', 'revocation_reason',
    ];

    protected $hidden = ['session_key_hash'];

    protected function casts(): array
    {
        return [
            'mfa_completed' => 'boolean',
            'password_version' => 'integer',
            'security_version' => 'integer',
            'authorization_version' => 'integer',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function mfaChallenges(): HasMany
    {
        return $this->hasMany(MfaChallenge::class);
    }
}
