<?php

namespace App\DTOs;

class CostSnapshotDTO
{
    public function __construct(
        public string $entity_type,
        public int $entity_id,
        public \DateTimeInterface $as_of_date,
        public string $method,
        public string $unit_cost,
        public int $currency_id,
        public ?array $details = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            entity_type: (string)$d['entity_type'],
            entity_id: (int)$d['entity_id'],
            as_of_date: $d['as_of_date'] instanceof \DateTimeInterface ? $d['as_of_date'] : new \DateTimeImmutable($d['as_of_date']),
            method: (string)$d['method'],
            unit_cost: (string)$d['unit_cost'],
            currency_id: (int)$d['currency_id'],
            details: $d['details'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'as_of_date' => $this->as_of_date->format('Y-m-d H:i:s'),
            'method' => $this->method,
            'unit_cost' => $this->unit_cost,
            'currency_id' => $this->currency_id,
            'details' => $this->details,
        ];
    }
}
