<?php

namespace App\Modules\Identity\Services;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Modules\Identity\Contracts\RoleRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly SecurityAuditService $audit,
    ) {}

    /** @param array<string, mixed> $attributes @param array<int, int> $permissionIds */
    public function create(Tenant $tenant, User $actor, array $attributes, array $permissionIds = []): Role
    {
        $this->assertActorTenant($tenant, $actor);

        return DB::transaction(function () use ($tenant, $actor, $attributes, $permissionIds): Role {
            $attributes['tenant_id'] = $tenant->getKey();
            $attributes['name'] = Str::slug($attributes['name']);
            $attributes['is_system'] = false;
            $role = $this->roles->create($attributes);
            $this->roles->syncPermissions($role, $permissionIds);
            $this->audit->record($tenant->getKey(), null, 'authorization', 'role.created', [
                'actor_id' => $actor->getKey(), 'target_type' => Role::class,
                'target_id' => $role->public_id, 'after' => $role->only(['name', 'label', 'status']),
            ]);

            return $role->load('permissions');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Tenant $tenant, User $actor, string $publicId, array $attributes): Role
    {
        $role = $this->find($tenant, $publicId);
        $this->assertActorTenant($tenant, $actor);
        $this->assertMutable($role);
        $before = $role->only(['name', 'label', 'description', 'status']);
        $allowed = collect($attributes)->only(['name', 'label', 'description', 'status'])->all();
        if (isset($allowed['name'])) {
            $allowed['name'] = Str::slug($allowed['name']);
        }
        $role = $this->roles->update($role, $allowed);
        $this->bumpRoleUsers($role);
        $this->audit->record($tenant->getKey(), null, 'authorization', 'role.updated', [
            'actor_id' => $actor->getKey(), 'target_type' => Role::class,
            'target_id' => $role->public_id, 'before' => $before,
            'after' => $role->only(['name', 'label', 'description', 'status']),
        ]);

        return $role;
    }

    /** @param array<int, int> $permissionIds */
    public function syncPermissions(Tenant $tenant, User $actor, string $publicId, array $permissionIds): Role
    {
        $role = $this->find($tenant, $publicId);
        $this->assertActorTenant($tenant, $actor);
        $this->assertMutable($role);
        $before = $role->permissions()->pluck('permissions.id')->all();
        $this->roles->syncPermissions($role, $permissionIds);
        $this->bumpRoleUsers($role);
        $this->audit->record($tenant->getKey(), null, 'authorization', 'role.permissions_synced', [
            'actor_id' => $actor->getKey(), 'target_type' => Role::class,
            'target_id' => $role->public_id, 'before' => ['permission_ids' => $before],
            'after' => ['permission_ids' => array_values($permissionIds)],
        ]);

        return $role->load('permissions');
    }

    public function delete(Tenant $tenant, User $actor, string $publicId): void
    {
        $role = $this->find($tenant, $publicId);
        $this->assertActorTenant($tenant, $actor);
        $this->assertMutable($role);
        DB::transaction(function () use ($tenant, $actor, $role): void {
            $this->bumpRoleUsers($role);
            $role->delete();
            $this->audit->record($tenant->getKey(), null, 'authorization', 'role.deleted', [
                'actor_id' => $actor->getKey(), 'target_type' => Role::class,
                'target_id' => $role->public_id, 'before' => $role->only(['name', 'label', 'status']),
            ]);
        });
    }

    public function grant(Tenant $tenant, Branch $branch, User $user, Role $role, User $actor): UserBranchRole
    {
        if ($branch->tenant_id !== $tenant->getKey() || $user->tenant_id !== $tenant->getKey()
            || $role->tenant_id !== $tenant->getKey() || $role->status !== 'active') {
            throw new IdentityException('TENANT_SCOPE_VIOLATION', 403, 'The role grant is outside the tenant scope.');
        }
        $this->assertActorTenant($tenant, $actor);
        $grant = $this->roles->grant(
            $tenant->getKey(), $branch->getKey(), $user->getKey(), $role->getKey(), $actor->getKey(),
        );
        $this->bumpUser($user);
        $this->audit->record($tenant->getKey(), $branch->getKey(), 'authorization', 'role.granted', [
            'actor_id' => $actor->getKey(), 'target_type' => User::class,
            'target_id' => $user->public_id, 'metadata' => ['role' => $role->name],
        ]);

        return $grant;
    }

    public function revokeGrant(UserBranchRole $grant, User $actor, string $reason): void
    {
        if ($grant->tenant_id !== $actor->tenant_id) {
            throw new IdentityException('TENANT_SCOPE_VIOLATION', 403, 'The role grant is outside the tenant scope.');
        }
        $this->roles->revokeGrant($grant, $actor->getKey(), $reason);
        $this->bumpUser($grant->user);
        $this->audit->record($grant->tenant_id, $grant->branch_id, 'authorization', 'role.revoked', [
            'actor_id' => $actor->getKey(), 'target_type' => User::class,
            'target_id' => $grant->user->public_id, 'reason' => $reason,
        ]);
    }

    private function find(Tenant $tenant, string $publicId): Role
    {
        return $this->roles->findForTenant($tenant->getKey(), $publicId)
            ?? throw new IdentityException('ROLE_NOT_FOUND', 404, 'The role was not found.');
    }

    private function assertActorTenant(Tenant $tenant, User $actor): void
    {
        if ($actor->tenant_id !== $tenant->getKey()) {
            throw new IdentityException('TENANT_SCOPE_VIOLATION', 403, 'The actor is outside the tenant scope.');
        }
    }

    private function assertMutable(Role $role): void
    {
        if ($role->is_system) {
            throw new IdentityException('SYSTEM_ROLE_IMMUTABLE', 409, 'System roles cannot be modified.');
        }
    }

    private function bumpRoleUsers(Role $role): void
    {
        $ids = $role->users()->pluck('users.id')->merge($role->branchGrants()->pluck('user_id'))->unique();
        User::withoutGlobalScopes()->whereIn('id', $ids)->increment('authorization_version');
    }

    private function bumpUser(User $user): void
    {
        User::withoutGlobalScopes()->whereKey($user->getKey())->increment('authorization_version');
    }
}
