<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostSnapshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'entity_type' => (string)$this->entity_type,
            'entity_id' => (string)$this->entity_id,
            'as_of_date' => $this->as_of_date?->toAtomString(),
            'method' => (string)$this->method,
            'unit_cost' => (float)$this->unit_cost,
            'currency_id' => $this->currency_id ? (string)$this->currency_id : null,
            'details' => $this->details,
        ];
    }
}
