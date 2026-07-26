<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string)$this->id,
            'owner_type' => (string)$this->owner_type,
            'owner_id' => (string)$this->owner_id,
            'active_version_id' => $this->active_version_id ? (string)$this->active_version_id : null,
            'lock_version' => (int)($this->lock_version ?? 0),
            'active_version' => $this->whenLoaded('activeVersion', fn() => new RecipeVersionResource($this->activeVersion)),
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at,
        ];
    }
}
