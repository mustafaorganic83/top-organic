<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'lock_version' => 'integer'];
    }

    public function branchMethods(): HasMany
    {
        return $this->hasMany(BranchPaymentMethod::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
