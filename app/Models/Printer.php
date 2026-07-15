<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Printer extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['connection_config' => 'array', 'lock_version' => 'integer'];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(PrintRoute::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
