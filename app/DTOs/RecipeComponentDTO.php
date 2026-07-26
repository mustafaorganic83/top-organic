<?php

namespace App\DTOs;

class RecipeComponentDTO
{
    public function __construct(
        public string $component_type, // 'stock_item' | 'semi_finished_product'
        public int $component_id,
        public string $quantity,
        public ?int $waste_bps,
        public ?int $sort_order,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            component_type: (string)$d['component_type'],
            component_id: (int)$d['component_id'],
            quantity: (string)$d['quantity'],
            waste_bps: isset($d['waste_bps']) ? (int)$d['waste_bps'] : null,
            sort_order: isset($d['sort_order']) ? (int)$d['sort_order'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'component_type' => $this->component_type,
            'component_id' => $this->component_id,
            'quantity' => $this->quantity,
            'waste_bps' => $this->waste_bps,
            'sort_order' => $this->sort_order,
        ];
    }
}
