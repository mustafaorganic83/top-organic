<?php

namespace App\Modules\Identity\Contracts;

use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchRole;
use Illuminate\Support\Collection;

interface RoleRepository
{
    /** @return Collection<int, string> */
    public function permissionNames(User $user, ?string $branchId): Collection;

    public function findForTenant(string $tenantId, string $publicId): ?Role;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Role;

    /** @param array<string, mixed> $attributes */
    public function update(Role $role, array $attributes): Role;

    /** @param array<int, int> $permissionIds */
    public function syncPermissions(Role $role, array $permissionIds): void;

    public function grant(string $tenantId, string $branchId, int $userId, int $roleId, ?int $actorId): UserBranchRole;

    public function revokeGrant(UserBranchRole $grant, ?int $actorId, string $reason): void;
}
