<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch is a first-class scoping dimension (architecture doc 03). Belongs
 * to a tenant; users are granted access to one or many branches.
 */
class Branch extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
