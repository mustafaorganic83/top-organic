<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Data\NormalizedIdentifier;
use App\Modules\Identity\Exceptions\IdentityException;

class IdentifierNormalizer
{
    public function normalize(string $identifier): NormalizedIdentifier
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new IdentityException('INVALID_IDENTIFIER', 422, 'An email, phone, or employee code is required.');
        }

        if (str_contains($identifier, '@')) {
            $email = mb_strtolower($identifier);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new IdentityException('INVALID_IDENTIFIER', 422, 'The email address is invalid.');
            }

            return new NormalizedIdentifier('email', $email);
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if ($this->looksLikePhone($identifier, $digits)) {
            return new NormalizedIdentifier('phone', $this->normalizeIraqiPhone($digits));
        }

        return new NormalizedIdentifier('employee_code', mb_strtoupper($identifier));
    }

    private function looksLikePhone(string $raw, string $digits): bool
    {
        return str_starts_with($raw, '+') || str_starts_with($digits, '00964')
            || (preg_match('/^[+0-9\s().-]+$/', $raw) && strlen($digits) >= 10);
    }

    private function normalizeIraqiPhone(string $digits): string
    {
        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '964'.substr($digits, 1);
        } elseif (str_starts_with($digits, '7')) {
            $digits = '964'.$digits;
        }

        if (! preg_match('/^9647[0-9]{9}$/', $digits)) {
            throw new IdentityException('INVALID_IDENTIFIER', 422, 'The Iraqi phone number is invalid.');
        }

        return '+'.$digits;
    }
}
