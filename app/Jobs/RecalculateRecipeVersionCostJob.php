<?php

namespace App\Jobs;

use App\Models\RecipeVersion;
use App\Services\Costing\CostMethod;
use App\Services\Snapshot\SnapshotEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class RecalculateRecipeVersionCostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string|int $recipeVersionId, public ?string $method = null)
    {}

    public function handle(SnapshotEngine $snapshots): void
    {
        /** @var RecipeVersion|null $version */
        $version = RecipeVersion::query()->find($this->recipeVersionId);
        if (!$version) return;
        $asOf = Carbon::now();
        $method = $this->method ?? CostMethod::WEIGHTED_AVG;
        $snapshots->snapshotRecipe($version, $asOf, $method, []);
    }
}
