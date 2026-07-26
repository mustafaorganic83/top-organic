<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CostSnapshotResource;
use App\Models\CostSnapshot;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockItem;
use App\Services\Costing\CostingEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CostingApiController extends Controller
{
    public function ingredient(Request $request, StockItem $stockItem, CostingEngine $engine)
    {
        $asOf = Carbon::parse($request->query('as_of', now()));
        $method = $request->query('method', 'LAST_PURCHASE');
        $unit = $engine->ingredientUnitCost((int)$stockItem->id, $asOf, $method, []);
        return response()->json(['data' => ['stock_item_id'=>(string)$stockItem->id,'as_of'=>$asOf->toAtomString(),'method'=>$method,'unit_cost'=>$unit]]);
    }

    public function recipeVersion(Request $request, RecipeVersion $version, CostingEngine $engine)
    {
        $asOf = Carbon::parse($request->query('as_of', now()));
        $method = $request->query('method', 'WEIGHTED_AVG');
        $out = $engine->recipeCost($version, $asOf, $method, []);
        return response()->json(['data' => $out + ['recipe_version_id'=>(string)$version->id,'as_of'=>$asOf->toAtomString(),'method'=>$method]]);
    }

    public function recipeActive(Request $request, Recipe $recipe, CostingEngine $engine)
    {
        $version = $recipe->activeVersion()->firstOrFail();
        return $this->recipeVersion($request, $version, $engine);
    }

    public function createSnapshot(Request $request, CostingEngine $engine): CostSnapshotResource
    {
        $this->authorize('create', CostSnapshot::class);
        $data = $request->validate([
            'entity_type' => ['required','string','max:120'],
            'entity_id' => ['required'],
            'as_of' => ['nullable','date'],
            'method' => ['nullable','string','max:64'],
        ]);
        $asOf = Carbon::parse($data['as_of'] ?? now());
        $method = $data['method'] ?? 'WEIGHTED_AVG';
        $unit = 0.0;
        if ($data['entity_type'] === StockItem::class) {
            $unit = $engine->ingredientUnitCost((int)$data['entity_id'], $asOf, $method, []);
        } elseif ($data['entity_type'] === RecipeVersion::class) {
            $v = RecipeVersion::query()->findOrFail($data['entity_id']);
            $unit = (float)($engine->recipeCost($v, $asOf, $method, [])['unit_cost'] ?? 0.0);
        }
        $snap = CostSnapshot::query()->create([
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'as_of_date' => $asOf,
            'method' => $method,
            'unit_cost' => $unit,
            'details' => null,
        ]);
        return new CostSnapshotResource($snap);
    }
}
