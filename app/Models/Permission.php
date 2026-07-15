<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A granular RBAC permission (e.g. "orders.create"), grouped into roles.
 */
class Permission extends Model
{
    protected $fillable = [
        'name',
        'label',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
