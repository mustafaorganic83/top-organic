<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Data;

/**
 * Trusted identity context injected into every Accounting service call.
 * Values are resolved from the JWT-verified identity middleware and passed
 * through request classes — never sourced from user-supplied request body.
 */
final readonly class AccountingContext
{
    public function __construct(
        public string $tenantId,
        public ?string $branchId,
        public string $userId,
        public ?string $deviceId,
    ) {}
}
