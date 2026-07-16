<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Floor extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['layout_revision' => 'integer', 'layout' => 'array', 'lock_version' => 'integer'];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
