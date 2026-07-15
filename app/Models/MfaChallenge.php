<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaChallenge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'user_id', 'mfa_method_id', 'auth_session_id', 'type',
        'challenge_hash', 'attempts', 'ip_address', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['challenge_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(MfaMethod::class, 'mfa_method_id');
    }

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }
}
