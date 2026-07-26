<?php

declare(strict_types=1);

namespace App\Modules\Menu\Support;

/**
 * Presentation-only conversion between the integer minor units the costing
 * engine works in and the strings the UI and reports display. Decimal places
 * come from the region pack, so IQD renders whole and USD renders to cents.
 */
final class MoneyFormatter
{
    private const BPS = 10000;

    /** Format a minor-unit amount for display, without the currency code. */
    public static function amount(?int $minorUnits, ?string $currency): string
    {
        if ($minorUnits === null) {
            return '—';
        }

        $decimals = self::decimals($currency);

        return number_format($minorUnits / (10 ** $decimals), $decimals);
    }

    /** Format a minor-unit amount followed by its currency code. */
    public static function money(?int $minorUnits, ?string $currency): string
    {
        if ($minorUnits === null) {
            return '—';
        }

        return trim(self::amount($minorUnits, $currency).' '.($currency ?? ''));
    }

    /** Render a basis-point ratio as a percentage string. */
    public static function percent(?int $bps, int $decimals = 2): string
    {
        if ($bps === null) {
            return '—';
        }

        return number_format($bps / (self::BPS / 100), $decimals).'%';
    }

    /**
     * Parse a decimal amount typed by a user back into minor units, so the
     * costing engine only ever receives integers.
     */
    public static function toMinorUnits(int|float|string|null $amount, ?string $currency): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** Convert minor units back to the decimal value shown in form inputs. */
    public static function toDecimal(?int $minorUnits, ?string $currency): float
    {
        return $minorUnits === null ? 0.0 : $minorUnits / (10 ** self::decimals($currency));
    }

    private static function decimals(?string $currency): int
    {
        $currency ??= config('region.currency.primary', 'IQD');

        return (int) (config('region.currency.decimals.'.$currency) ?? 2);
    }
}
