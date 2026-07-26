<?php

namespace App\Services\Recalc;

use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

class DependencyResolver
{
    /**
     * Return impacted recipe_version IDs for a changed stock item.
     * Includes direct recipes and indirect via nested semi-finished products.
     *
     * @return array<int,int> recipe_version_ids
     */
    public function resolveByStockItem(string|int $itemId): array
    {
        $affected = collect();
        $queue = collect();

        // Seed: recipe versions that directly use this stock item
        $direct = RecipeComponent::query()
            ->where('component_type', 'stock_item')
            ->where('component_id', $itemId)
            ->pluck('recipe_version_id');
        $affected = $affected->merge($direct)->unique();
        $queue = $queue->merge($this->toPreparedIdsFromVersions($direct))->unique();

        // BFS upwards through semi-finished usages
        $visitedPrepared = collect();
        while ($queue->isNotEmpty()) {
            $preparedId = $queue->shift();
            if ($visitedPrepared->contains($preparedId)) { continue; }
            $visitedPrepared->push($preparedId);

            // Versions that use this prepared as component
            $versions = RecipeComponent::query()
                ->where('component_type', 'semi_finished_product')
                ->where('component_id', $preparedId)
                ->pluck('recipe_version_id');
            $newVers = $versions->diff($affected);
            $affected = $affected->merge($newVers)->unique();

            // Queue owners of these versions (prepared up the chain)
            $queue = $queue->merge($this->toPreparedIdsFromVersions($newVers))->unique();
        }
        return $affected->values()->all();
    }

    /** Resolve impacted versions for a prepared item change. */
    public function resolveByPrepared(string|int $preparedId): array
    {
        $affected = collect();
        $queue = collect([$preparedId]);
        $visitedPrepared = collect();
        while ($queue->isNotEmpty()) {
            $pid = $queue->shift();
            if ($visitedPrepared->contains($pid)) { continue; }
            $visitedPrepared->push($pid);

            $versions = RecipeComponent::query()
                ->where('component_type', 'semi_finished_product')
                ->where('component_id', $pid)
                ->pluck('recipe_version_id');
            $newVers = $versions->diff($affected);
            $affected = $affected->merge($newVers)->unique();
            $queue = $queue->merge($this->toPreparedIdsFromVersions($newVers))->unique();
        }
        return $affected->values()->all();
    }

    /** Resolve impacted versions starting from a specific recipe version (modified). */
    public function resolveByRecipeVersion(string|int $versionId): array
    {
        $affected = collect([$versionId]);
        $queue = $this->toPreparedIdsFromVersions(collect([$versionId]));
        $visitedPrepared = collect();
        while ($queue->isNotEmpty()) {
            $pid = $queue->shift();
            if ($visitedPrepared->contains($pid)) { continue; }
            $visitedPrepared->push($pid);
            $versions = RecipeComponent::query()
                ->where('component_type', 'semi_finished_product')
                ->where('component_id', $pid)
                ->pluck('recipe_version_id');
            $newVers = $versions->diff($affected);
            $affected = $affected->merge($newVers)->unique();
            $queue = $queue->merge($this->toPreparedIdsFromVersions($newVers))->unique();
        }
        return $affected->values()->all();
    }

    /** Map recipe_version IDs to prepared IDs (owners up the chain). */
    private function toPreparedIdsFromVersions(Collection $versionIds): Collection
    {
        if ($versionIds->isEmpty()) return collect();
        $rows = RecipeVersion::query()->whereIn('id', $versionIds)->get(['id','recipe_id']);
        $recipeIds = $rows->pluck('recipe_id');
        $owners = Recipe::query()->whereIn('id', $recipeIds)->get(['id','owner_type','owner_id']);
        return $owners->filter(fn($r)=>$r->owner_type === \App\Models\SemiFinishedProduct::class)
            ->pluck('owner_id')->unique();
    }
}
