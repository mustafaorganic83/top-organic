<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

final readonly class CatalogSnapshot
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public string $productName,
        public ?string $variantName,
        public string $sku,
        public ?string $barcode,
        public int $unitPriceAmount,
        public string $currency,
        public ?string $taxClassId,
        public ?string $taxClassCode,
        public int $taxRateBps,
        public bool $taxInclusive,
        public string $priceListId,
        public int $priceRevision,
        public int $catalogRevision,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
