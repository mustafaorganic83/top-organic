<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'branch_id' => (string)$this->branch_id,
            'warehouse_id' => (string)$this->warehouse_id,
            'stockable_type' => (string)$this->stockable_type,
            'stockable_id' => (string)$this->stockable_id,
            'quantity_on_hand' => (float)$this->quantity_on_hand,
            'reserved_quantity' => (float)$this->reserved_quantity,
            'average_cost_amount' => (int)$this->average_cost_amount,
            'updated_at' => (string)$this->updated_at,
        ];
    }
}
