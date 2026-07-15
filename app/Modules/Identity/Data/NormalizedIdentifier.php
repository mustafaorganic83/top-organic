<?php

namespace App\Modules\Identity\Data;

final readonly class NormalizedIdentifier
{
    public function __construct(
        public string $column,
        public string $value,
    ) {}
}
