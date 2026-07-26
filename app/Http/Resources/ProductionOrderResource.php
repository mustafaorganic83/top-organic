<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'branch_id' => (string)$this->branch_id,
            'warehouse_id' => (string)$this->warehouse_id,
            'prepared_recipe_id' => (string)$this->prepared_recipe_id,
            'planned_qty' => (float)$this->planned_qty,
            'actual_qty' => (float)($this->actual_qty ?? 0.0),
            'status' => (string)$this->status,
            'scheduled_at' => $this->scheduled_at?->toAtomString(),
            'started_at' => $this->started_at?->toAtomString(),
            'completed_at' => $this->completed_at?->toAtomString(),
        ];
    }
}
