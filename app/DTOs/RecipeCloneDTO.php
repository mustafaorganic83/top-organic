<?php

namespace App\DTOs;

class RecipeCloneDTO
{
    public function __construct(
        public int $source_recipe_id,
        public ?string $new_code,
        public ?string $new_name_ar,
        public ?string $new_name_en,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            source_recipe_id: (int)$d['source_recipe_id'],
            new_code: $d['new_code'] ?? null,
            new_name_ar: $d['new_name_ar'] ?? null,
            new_name_en: $d['new_name_en'] ?? null,
        );
    }
}
