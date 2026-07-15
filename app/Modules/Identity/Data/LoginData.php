<?php

namespace App\Modules\Identity\Data;

final readonly class LoginData
{
    public function __construct(
        public string $tenantSlug,
        public string $identifier,
        public string $password,
        public ?string $branch = null,
        public ?string $device = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $rememberedDeviceToken = null,
    ) {}
}
