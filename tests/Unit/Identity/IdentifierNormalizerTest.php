<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Services\IdentifierNormalizer;
use PHPUnit\Framework\TestCase;

class IdentifierNormalizerTest extends TestCase
{
    public function test_it_normalizes_supported_identifiers_including_iraqi_mobile_numbers(): void
    {
        $normalizer = new IdentifierNormalizer;

        $this->assertSame('user@example.com', $normalizer->normalize(' USER@Example.com ')->value);
        $this->assertSame('+9647501234567', $normalizer->normalize('0750 123 4567')->value);
        $this->assertSame('+9647501234567', $normalizer->normalize('00964 750 123 4567')->value);
        $this->assertSame('EMP-42', $normalizer->normalize(' emp-42 ')->value);
    }
}
