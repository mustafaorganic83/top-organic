<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An RBAC role (admin, manager, waiter, cashier, kitchen, ...) grouping a set
 * of permissions (architecture doc 06).
 */
class Role extends Model
{
    protected $fillable = [
        'name',
        'label',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Whether this role grants the given permission name.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()
            ->where('name', $permission)
            ->exists();
    }
}
