<?php

namespace App\Observers;

use App\Models\SemiFinishedProduct;
use App\Services\Snapshot\SnapshotEngine;
use Illuminate\Support\Carbon;

class SemiFinishedProductObserver
{
    public function __construct(private SnapshotEngine $snapshots)
    {
    }

    public function saved(SemiFinishedProduct $item): void
    {
        $recipe = $item->recipe()->first();
        if ($recipe) {
            $active = $recipe->activeVersion()->first() ?? $recipe->versions()->latest('created_at')->first();
            if ($active) { $this->snapshots->snapshotRecipe($active, Carbon::now()); }
        }
    }
}
