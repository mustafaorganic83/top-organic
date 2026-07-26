<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
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
            'quantity_delta' => (float)$this->quantity_delta,
            'unit' => (string)$this->unit,
            'reason' => (string)$this->reason,
            'unit_cost_amount' => (int)($this->unit_cost_amount ?? 0),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'client_operation_id' => $this->client_operation_id,
            'occurred_at' => $this->occurred_at?->toAtomString(),
            'created_at' => (string)$this->created_at,
        ];
    }
}
