<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

use App\Models\GiftCard;

final readonly class IssuedGiftCard
{
    public function __construct(public GiftCard $giftCard, public string $token) {}
}
