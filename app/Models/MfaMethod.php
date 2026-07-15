<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MfaMethod extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'label', 'secret_ciphertext',
        'credential_hash', 'public_key', 'is_primary', 'verified_at',
        'last_used_at', 'disabled_at',
    ];

    protected $hidden = ['secret_ciphertext', 'credential_hash'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function challenges(): HasMany
    {
        return $this->hasMany(MfaChallenge::class);
    }
}
