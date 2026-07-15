<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An RBAC role (admin, manager, waiter, cashier, kitchen, ...) grouping a set
 * of permissions (architecture doc 06).
 */
class Role extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'label',
        'description',
        'is_system',
        'status',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function branchGrants(): HasMany
    {
        return $this->hasMany(UserBranchRole::class);
    }

    public function scopeAvailableToTenant(Builder $query, ?string $tenantId): Builder
    {
        $tenantColumn = $query->qualifyColumn('tenant_id');

        return $query->where(function (Builder $query) use ($tenantColumn, $tenantId): void {
            $query->whereNull($tenantColumn);

            if ($tenantId !== null) {
                $query->orWhere($tenantColumn, $tenantId);
            }
        });
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
