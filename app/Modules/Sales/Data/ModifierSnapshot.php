<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

final readonly class ModifierSnapshot
{
    public function __construct(
        public string $groupId,
        public string $optionId,
        public string $groupName,
        public string $optionName,
        public int $unitSurchargeAmount,
        public string $currency,
    ) {}
}
