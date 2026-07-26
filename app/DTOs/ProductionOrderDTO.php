<?php

namespace App\DTOs;

class ProductionOrderDTO
{
    public function __construct(
        public int $branch_id,
        public int $warehouse_id,
        public int $prepared_recipe_id,
        public string $planned_qty,
        public ?string $actual_qty,
        public ?int $uom_id,
        public string $status = 'PLANNED',
        public ?\DateTimeInterface $scheduled_at = null,
        public ?\DateTimeInterface $started_at = null,
        public ?\DateTimeInterface $completed_at = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            branch_id: (int)$d['branch_id'],
            warehouse_id: (int)$d['warehouse_id'],
            prepared_recipe_id: (int)$d['prepared_recipe_id'],
            planned_qty: (string)$d['planned_qty'],
            actual_qty: isset($d['actual_qty']) ? (string)$d['actual_qty'] : null,
            uom_id: isset($d['uom_id']) ? (int)$d['uom_id'] : null,
            status: $d['status'] ?? 'PLANNED',
            scheduled_at: empty($d['scheduled_at']) ? null : new \DateTimeImmutable($d['scheduled_at']),
            started_at: empty($d['started_at']) ? null : new \DateTimeImmutable($d['started_at']),
            completed_at: empty($d['completed_at']) ? null : new \DateTimeImmutable($d['completed_at']),
        );
    }

    public function toArray(): array
    {
        return [
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'prepared_recipe_id' => $this->prepared_recipe_id,
            'planned_qty' => $this->planned_qty,
            'actual_qty' => $this->actual_qty,
            'uom_id' => $this->uom_id,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->format('Y-m-d H:i:s'),
            'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
