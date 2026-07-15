<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch is a first-class scoping dimension (architecture doc 03). Belongs
 * to a tenant; users are granted access to one or many branches.
 */
class Branch extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function roleGrants(): HasMany
    {
        return $this->hasMany(UserBranchRole::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    public function kdsStations(): HasMany
    {
        return $this->hasMany(KdsStation::class);
    }
}
