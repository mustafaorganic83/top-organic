<?php

namespace App\Policies;

use App\Models\CostHistory;
use App\Models\User;

class CostHistoryPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, CostHistory $model): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, CostHistory $model): bool { return true; }
    public function delete(User $user, CostHistory $model): bool { return true; }
    public function restore(User $user, CostHistory $model): bool { return true; }
    public function forceDelete(User $user, CostHistory $model): bool { return false; }
}
