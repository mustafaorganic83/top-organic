<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefreshToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'auth_session_id', 'family_id', 'parent_token_id', 'token_hash',
        'expires_at', 'used_at', 'revoked_at', 'replaced_by_token_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_token_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_token_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_token_id');
    }
}
