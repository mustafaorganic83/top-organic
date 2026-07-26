<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;

class ProductionOrderPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, ProductionOrder $model): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, ProductionOrder $model): bool { return true; }
    public function delete(User $user, ProductionOrder $model): bool { return true; }
    public function restore(User $user, ProductionOrder $model): bool { return true; }
    public function forceDelete(User $user, ProductionOrder $model): bool { return false; }
}
