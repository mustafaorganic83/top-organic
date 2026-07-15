<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * RBAC helper applied to the User model. Roles group permissions; permissions
 * are enforced at the domain boundary via Gate/policies (architecture doc 06),
 * not just in the UI.
 */
trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $names = is_array($roles) ? $roles : [$roles];

        return $this->roles()->whereIn('name', $names)->exists();
    }

    /**
     * Grant one or more roles by name. Roles must already exist (seeded).
     */
    public function assignRole(string ...$names): self
    {
        $ids = Role::whereIn('name', $names)->pluck('id');

        $this->roles()->syncWithoutDetaching($ids);

        return $this;
    }

    /**
     * Whether any of the user's roles grant the given permission name.
     */
    public function hasPermissionTo(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * The distinct set of permission names granted across all of the user's
     * roles — used to mirror RBAC onto Sanctum token abilities.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        return $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }
}
