<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PreparedItemResource;
use App\Http\Resources\RecipeResource;
use App\Models\SemiFinishedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PreparedItemApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = SemiFinishedProduct::query();
        if ($s = $request->query('search')) {
            $q->where('name', 'like', '%'.$s.'%');
        }
        return PreparedItemResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): PreparedItemResource
    {
        $data = $request->validate([
            'name' => ['required','string','max:200'],
            'yield_quantity' => ['nullable','numeric'],
        ]);
        $m = SemiFinishedProduct::query()->create($data);
        return new PreparedItemResource($m);
    }

    public function show(SemiFinishedProduct $prepared): PreparedItemResource
    {
        return new PreparedItemResource($prepared);
    }

    public function update(Request $request, SemiFinishedProduct $prepared): PreparedItemResource
    {
        $data = $request->validate([
            'name' => ['sometimes','string','max:200'],
            'yield_quantity' => ['sometimes','numeric'],
        ]);
        $prepared->fill($data)->save();
        return new PreparedItemResource($prepared->refresh());
    }

    public function destroy(SemiFinishedProduct $prepared)
    {
        $prepared->delete();
        return response()->json(['ok'=>true]);
    }

    public function recipe(SemiFinishedProduct $prepared): RecipeResource
    {
        $recipe = $prepared->recipe()->with('activeVersion')->firstOrFail();
        return new RecipeResource($recipe);
    }
}
