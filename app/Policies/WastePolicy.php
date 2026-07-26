<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Waste;

class WastePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Waste $model): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Waste $model): bool { return true; }
    public function delete(User $user, Waste $model): bool { return true; }
    public function restore(User $user, Waste $model): bool { return true; }
    public function forceDelete(User $user, Waste $model): bool { return false; }
}
