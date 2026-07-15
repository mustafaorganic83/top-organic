<?php

namespace App\Modules\Identity\Repositories;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Identity\Contracts\IdentityRepository;
use Illuminate\Database\Eloquent\Builder;

class EloquentIdentityRepository implements IdentityRepository
{
    public function findActiveTenantBySlug(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', $slug)->where('is_active', true)->first();
    }

    public function findActiveUser(Tenant $tenant, string $column, string $value): ?User
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where($column, $value)
            ->where('is_active', true)
            ->where('account_status', 'active')
            ->first();
    }

    public function findUserByPublicId(string $publicId): ?User
    {
        return User::withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function findGrantedBranch(User $user, string $reference): ?Branch
    {
        return $this->grantedBranches($user)
            ->where(fn (Builder $query) => $query
                ->where('branches.id', $reference)
                ->orWhere('branches.code', $reference))
            ->first();
    }

    public function firstGrantedBranch(User $user): ?Branch
    {
        return $this->grantedBranches($user)->orderBy('branches.code')->first();
    }

    /** @return Builder<Branch> */
    private function grantedBranches(User $user): Builder
    {
        return Branch::withoutGlobalScopes()
            ->where('branches.tenant_id', $user->tenant_id)
            ->where('branches.is_active', true)
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()))
                    ->orWhereHas('roleGrants', fn (Builder $grants) => $grants
                        ->where('user_id', $user->getKey())
                        ->whereNull('revoked_at')
                        ->where('effective_at', '<=', now())
                        ->where(fn (Builder $expiry) => $expiry
                            ->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            });
    }
}
