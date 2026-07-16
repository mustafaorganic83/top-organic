<?php

namespace App\Modules\Identity\Repositories;

use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Modules\Identity\Contracts\RoleRepository;
use Illuminate\Support\Collection;

class EloquentRoleRepository implements RoleRepository
{
    public function permissionNames(User $user, ?string $branchId): Collection
    {
        return $user->permissionNames($branchId);
    }

    public function findForTenant(string $tenantId, string $publicId): ?Role
    {
        return Role::query()->where('tenant_id', $tenantId)->where('public_id', $publicId)->first();
    }

    public function create(array $attributes): Role
    {
        return Role::query()->create($attributes)->refresh();
    }

    public function update(Role $role, array $attributes): Role
    {
        $role->fill($attributes)->save();

        return $role->refresh();
    }

    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
    }

    public function grant(string $tenantId, string $branchId, int $userId, int $roleId, ?int $actorId): UserBranchRole
    {
        return UserBranchRole::withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'revoked_at' => null,
        ], ['effective_at' => now(), 'granted_by' => $actorId]);
    }

    public function revokeGrant(UserBranchRole $grant, ?int $actorId, string $reason): void
    {
        $grant->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actorId,
            'revocation_reason' => $reason,
        ])->save();
    }
}
