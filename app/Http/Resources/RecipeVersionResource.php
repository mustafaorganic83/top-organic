<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'recipe_id' => (string)$this->recipe_id,
            'revision' => (int)($this->revision ?? 0),
            'yield_quantity' => (float)($this->yield_quantity ?? 0.0),
            'waste_bps' => (int)($this->waste_bps ?? 0),
            'ingredient_cost_amount' => (int)($this->ingredient_cost_amount ?? 0),
            'recipe_cost_amount' => (int)($this->recipe_cost_amount ?? 0),
            'published_at' => $this->published_at?->toAtomString(),
            'activated_at' => $this->activated_at?->toAtomString(),
        ];
    }
}
