<?php

namespace App\DTOs;

class WasteDTO
{
    public function __construct(
        public string $waste_type,
        public ?int $production_order_id,
        public ?int $item_id,
        public ?string $qty,
        public ?int $uom_id,
        public ?string $pct,
        public ?int $department_id,
        public ?int $warehouse_id,
        public ?string $reason,
        public \DateTimeInterface $occurred_at,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            waste_type: (string)$d['waste_type'],
            production_order_id: isset($d['production_order_id']) ? (int)$d['production_order_id'] : null,
            item_id: isset($d['item_id']) ? (int)$d['item_id'] : null,
            qty: isset($d['qty']) ? (string)$d['qty'] : null,
            uom_id: isset($d['uom_id']) ? (int)$d['uom_id'] : null,
            pct: isset($d['pct']) ? (string)$d['pct'] : null,
            department_id: isset($d['department_id']) ? (int)$d['department_id'] : null,
            warehouse_id: isset($d['warehouse_id']) ? (int)$d['warehouse_id'] : null,
            reason: $d['reason'] ?? null,
            occurred_at: $d['occurred_at'] instanceof \DateTimeInterface ? $d['occurred_at'] : new \DateTimeImmutable($d['occurred_at']),
        );
    }

    public function toArray(): array
    {
        return [
            'waste_type' => $this->waste_type,
            'production_order_id' => $this->production_order_id,
            'item_id' => $this->item_id,
            'qty' => $this->qty,
            'uom_id' => $this->uom_id,
            'pct' => $this->pct,
            'department_id' => $this->department_id,
            'warehouse_id' => $this->warehouse_id,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at->format('Y-m-d H:i:s'),
        ];
    }
}
