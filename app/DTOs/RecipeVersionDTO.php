<?php

namespace App\DTOs;

class RecipeVersionDTO
{
    public function __construct(
        public ?int $revision,
        public string $yield_quantity,
        public ?int $waste_bps,
        public ?array $nutrition,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            revision: isset($d['revision']) ? (int)$d['revision'] : null,
            yield_quantity: (string)$d['yield_quantity'],
            waste_bps: isset($d['waste_bps']) ? (int)$d['waste_bps'] : null,
            nutrition: $d['nutrition'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'revision' => $this->revision,
            'yield_quantity' => $this->yield_quantity,
            'waste_bps' => $this->waste_bps,
            'nutrition' => $this->nutrition,
        ];
    }
}
