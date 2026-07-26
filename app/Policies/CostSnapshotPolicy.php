<?php

namespace App\Policies;

use App\Models\CostSnapshot;
use App\Models\User;

class CostSnapshotPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, CostSnapshot $model): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, CostSnapshot $model): bool { return true; }
    public function delete(User $user, CostSnapshot $model): bool { return true; }
    public function restore(User $user, CostSnapshot $model): bool { return true; }
    public function forceDelete(User $user, CostSnapshot $model): bool { return false; }
}
