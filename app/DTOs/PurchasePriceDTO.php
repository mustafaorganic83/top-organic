<?php

namespace App\DTOs;

class PurchasePriceDTO
{
    public function __construct(
        public int $item_id,
        public int $supplier_id,
        public ?int $uom_id,
        public string $price,
        public int $currency_id,
        public \DateTimeInterface $effective_from,
        public ?\DateTimeInterface $effective_to,
        public string $source = 'MANUAL',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            item_id: (int)$data['item_id'],
            supplier_id: (int)$data['supplier_id'],
            uom_id: isset($data['uom_id']) ? (int)$data['uom_id'] : null,
            price: (string)$data['price'],
            currency_id: (int)$data['currency_id'],
            effective_from: $data['effective_from'] instanceof \DateTimeInterface ? $data['effective_from'] : new \DateTimeImmutable($data['effective_from']),
            effective_to: empty($data['effective_to']) ? null : ($data['effective_to'] instanceof \DateTimeInterface ? $data['effective_to'] : new \DateTimeImmutable($data['effective_to'])),
            source: $data['source'] ?? 'MANUAL',
        );
    }

    public function toArray(): array
    {
        return [
            'item_id' => $this->item_id,
            'supplier_id' => $this->supplier_id,
            'uom_id' => $this->uom_id,
            'price' => $this->price,
            'currency_id' => $this->currency_id,
            'effective_from' => $this->effective_from->format('Y-m-d H:i:s'),
            'effective_to' => $this->effective_to?->format('Y-m-d H:i:s'),
            'source' => $this->source,
        ];
    }
}
