<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Role;
use App\Models\UserBranchRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
    public function hasRole(string|array $roles, Branch|string|null $branch = null): bool
    {
        $names = is_array($roles) ? $roles : [$roles];

        if ($this->legacyRolesQuery()->whereIn('name', $names)->exists()) {
            return true;
        }

        return $branch !== null && $this->rolesForBranch($branch)->whereIn('roles.name', $names)->exists();
    }

    /**
     * Grant one or more roles by name. Roles must already exist (seeded).
     */
    public function assignRole(string ...$names): self
    {
        $roles = Role::availableToTenant($this->tenant_id)
            ->whereIn('name', $names)
            ->get()
            ->sortByDesc(fn (Role $role): bool => $role->tenant_id === $this->tenant_id)
            ->unique('name');

        $this->roles()->syncWithoutDetaching($roles->pluck('id'));

        return $this;
    }

    public function roleGrants(): HasMany
    {
        return $this->hasMany(UserBranchRole::class);
    }

    public function rolesForBranch(Branch|string $branch): BelongsToMany
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $this->belongsToMany(Role::class, 'user_branch_roles')
            ->withPivot(['id', 'tenant_id', 'branch_id', 'effective_at', 'expires_at', 'revoked_at'])
            ->availableToTenant($this->tenant_id)
            ->wherePivot('branch_id', $branchId)
            ->wherePivotNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('user_branch_roles.expires_at')
                    ->orWhere('user_branch_roles.expires_at', '>', now());
            });
    }

    public function assignRoleForBranch(Branch $branch, string ...$names): self
    {
        if ($this->tenant_id !== $branch->tenant_id) {
            throw new InvalidArgumentException('The user and branch must belong to the same tenant.');
        }

        $roles = Role::availableToTenant($this->tenant_id)->whereIn('name', $names)->get()
            ->sortByDesc(fn (Role $role): bool => $role->tenant_id === $this->tenant_id)
            ->unique('name');

        foreach ($roles as $role) {
            UserBranchRole::firstOrCreate([
                'tenant_id' => $this->tenant_id,
                'branch_id' => $branch->getKey(),
                'user_id' => $this->getKey(),
                'role_id' => $role->getKey(),
                'revoked_at' => null,
            ], ['effective_at' => now()]);
        }

        return $this;
    }

    /**
     * Whether any of the user's roles grant the given permission name.
     */
    public function hasPermissionTo(string $permission, Branch|string|null $branch = null): bool
    {
        if ($this->legacyRolesQuery()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists()) {
            return true;
        }

        return $branch !== null && $this->rolesForBranch($branch)
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * The distinct set of permission names granted across all of the user's
     * roles — used to mirror RBAC onto Sanctum token abilities.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(Branch|string|null $branch = null): Collection
    {
        $roles = $this->legacyRolesQuery()
            ->with('permissions:id,name')
            ->get();

        if ($branch !== null) {
            $roles = $roles->merge($this->rolesForBranch($branch)->with('permissions:id,name')->get());
        }

        return $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    private function legacyRolesQuery(): BelongsToMany
    {
        return $this->roles()->availableToTenant($this->tenant_id);
    }
}
