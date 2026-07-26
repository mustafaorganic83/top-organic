<?php

namespace App\Policies;

use App\Models\PurchasePrice;
use App\Models\User;

class PurchasePricePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, PurchasePrice $model): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, PurchasePrice $model): bool { return true; }
    public function delete(User $user, PurchasePrice $model): bool { return true; }
    public function restore(User $user, PurchasePrice $model): bool { return true; }
    public function forceDelete(User $user, PurchasePrice $model): bool { return false; }
}
