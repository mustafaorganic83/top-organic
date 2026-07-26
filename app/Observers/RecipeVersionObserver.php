<?php

namespace App\Observers;

use App\Models\RecipeVersion;
use App\Services\Snapshot\SnapshotEngine;
use Illuminate\Support\Carbon;

class RecipeVersionObserver
{
    public function __construct(private SnapshotEngine $snapshots)
    {
    }

    public function saved(RecipeVersion $version): void
    {
        $this->snapshots->snapshotRecipe($version, Carbon::now());
        \App\Jobs\EnqueueRecalcForChangedNode::dispatch('RECIPE_VERSION', $version->getKey())->onQueue('recalc');
    }
}
