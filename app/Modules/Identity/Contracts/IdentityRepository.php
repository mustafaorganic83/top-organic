<?php

namespace App\Modules\Identity\Contracts;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;

interface IdentityRepository
{
    public function findActiveTenantBySlug(string $slug): ?Tenant;

    public function findActiveUser(Tenant $tenant, string $column, string $value): ?User;

    public function findUserByPublicId(string $publicId): ?User;

    public function findGrantedBranch(User $user, string $reference): ?Branch;

    public function firstGrantedBranch(User $user): ?Branch;
}
