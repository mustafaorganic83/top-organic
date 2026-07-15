<?php

namespace App\Modules\Identity\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Identity\Contracts\RoleRepository;
use Illuminate\Support\Collection;

class PermissionResolver
{
    public function __construct(private readonly RoleRepository $roles) {}

    /** @return Collection<int, string> */
    public function resolve(User $user, Branch|string|null $branch = null): Collection
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $this->roles->permissionNames($user, $branchId)->sort()->values();
    }

    public function allows(User $user, string $permission, Branch|string|null $branch = null): bool
    {
        return $this->resolve($user, $branch)->contains($permission);
    }
}
