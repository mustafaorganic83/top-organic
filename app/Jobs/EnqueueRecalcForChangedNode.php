<?php

namespace App\Jobs;

use App\Services\Recalc\DependencyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnqueueRecalcForChangedNode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $kind, // ITEM|PREPARED|RECIPE_VERSION
        public string|int $id
    ) {}

    public function handle(DependencyResolver $resolver): void
    {
        $versions = match ($this->kind) {
            'ITEM' => $resolver->resolveByStockItem($this->id),
            'PREPARED' => $resolver->resolveByPrepared($this->id),
            'RECIPE_VERSION' => $resolver->resolveByRecipeVersion($this->id),
            default => [],
        };
        $versions = array_values(array_unique($versions));
        foreach ($versions as $vid) {
            RecalculateRecipeVersionCostJob::dispatch($vid)->onQueue('recalc');
        }
    }
}
