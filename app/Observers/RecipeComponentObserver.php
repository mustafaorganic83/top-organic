<?php

namespace App\Observers;

use App\Models\RecipeComponent;
use App\Services\Snapshot\SnapshotEngine;
use Illuminate\Support\Carbon;

class RecipeComponentObserver
{
    public function __construct(private SnapshotEngine $snapshots)
    {
    }

    public function saved(RecipeComponent $line): void
    {
        $version = $line->version()->first();
        if ($version) {
            $this->snapshots->snapshotRecipe($version, Carbon::now());
            \App\Jobs\EnqueueRecalcForChangedNode::dispatch('RECIPE_VERSION', (int)$version->getKey())->onQueue('recalc');
        }
    }

    public function deleted(RecipeComponent $line): void
    {
        $version = $line->version()->first();
        if ($version) {
            $this->snapshots->snapshotRecipe($version, Carbon::now());
            \App\Jobs\EnqueueRecalcForChangedNode::dispatch('RECIPE_VERSION', (int)$version->getKey())->onQueue('recalc');
        }
    }
}
