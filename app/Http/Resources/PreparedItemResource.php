<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreparedItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'name' => (string)($this->name ?? ''),
            'yield_quantity' => (float)($this->yield_quantity ?? 0.0),
            'lock_version' => (int)($this->lock_version ?? 0),
            'created_at' => (string)$this->created_at,
        ];
    }
}
