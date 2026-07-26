<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Http\Resources\RecipeVersionResource;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RecipeApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Recipe::query()->with('activeVersion');
        if ($owner = $request->query('owner_id')) {
            $q->where('owner_id', $owner);
        }
        return RecipeResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): RecipeResource
    {
        $data = $request->validate([
            'owner_type' => ['required','string','max:120'],
            'owner_id' => ['required'],
            'active_version_id' => ['nullable','string'],
        ]);
        $r = Recipe::query()->create($data);
        return new RecipeResource($r->load('activeVersion'));
    }

    public function show(Recipe $recipe): RecipeResource
    {
        return new RecipeResource($recipe->load('activeVersion'));
    }

    public function update(Request $request, Recipe $recipe): RecipeResource
    {
        $data = $request->validate([
            'active_version_id' => ['nullable','string'],
            'lock_version' => ['nullable','integer'],
        ]);
        $recipe->fill($data)->save();
        return new RecipeResource($recipe->refresh()->load('activeVersion'));
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();
        return response()->json(['ok' => true]);
    }

    public function versions(Request $request, Recipe $recipe)
    {
        $q = $recipe->versions()->orderByDesc('created_at');
        return RecipeVersionResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function createVersion(Request $request, Recipe $recipe): RecipeVersionResource
    {
        $data = $request->validate([
            'revision' => ['nullable','integer'],
            'yield_quantity' => ['required','numeric'],
            'waste_bps' => ['nullable','integer'],
        ]);
        $v = $recipe->versions()->create($data);
        return new RecipeVersionResource($v);
    }

    public function publishVersion(RecipeVersion $version): RecipeVersionResource
    {
        $version->published_at = now();
        $version->save();
        return new RecipeVersionResource($version->refresh());
    }

    public function activateVersion(RecipeVersion $version)
    {
        $recipe = $version->recipe;
        $recipe->active_version_id = $version->id; $recipe->save();
        $version->activated_at = now(); $version->save();
        return response()->json(['ok'=>true,'recipe_id'=>$recipe->id,'active_version_id'=>$version->id]);
    }
}
