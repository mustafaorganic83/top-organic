<?php

namespace App\DTOs;

class CostHistoryDTO
{
    public function __construct(
        public string $entity_type,
        public int $entity_id,
        public string $method,
        public string $unit_cost,
        public int $currency_id,
        public \DateTimeInterface $effective_from,
        public ?\DateTimeInterface $effective_to,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            entity_type: (string)$d['entity_type'],
            entity_id: (int)$d['entity_id'],
            method: (string)$d['method'],
            unit_cost: (string)$d['unit_cost'],
            currency_id: (int)$d['currency_id'],
            effective_from: $d['effective_from'] instanceof \DateTimeInterface ? $d['effective_from'] : new \DateTimeImmutable($d['effective_from']),
            effective_to: empty($d['effective_to']) ? null : ($d['effective_to'] instanceof \DateTimeInterface ? $d['effective_to'] : new \DateTimeImmutable($d['effective_to'])),
        );
    }

    public function toArray(): array
    {
        return [
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'method' => $this->method,
            'unit_cost' => $this->unit_cost,
            'currency_id' => $this->currency_id,
            'effective_from' => $this->effective_from->format('Y-m-d H:i:s'),
            'effective_to' => $this->effective_to?->format('Y-m-d H:i:s'),
        ];
    }
}
