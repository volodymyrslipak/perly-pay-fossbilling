<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * Money without floats. FOSSBilling keeps invoice totals as floats
 * (24.5) and decimal columns ("24.50"); Perly Pay takes fiat in minor units
 * (2450) and gives crypto as an integer string in the asset's smallest
 * unit ("24500000" with 6 decimals). Every conversion here is string or
 * integer arithmetic.
 */
final class Money
{
    /** The currencies Perly Pay prices invoices in. */
    public const CURRENCIES = ['USD', 'EUR'];

    /**
     * Any amount FOSSBilling gives ("24.5", 24.5, "24.50") as "24.50", or null.
     * A float (an invoice total) is read through its two-decimal text, the
     * only place one is touched.
     */
    public static function normalise(mixed $amount): ?string
    {
        if (is_float($amount) || is_int($amount)) {
            $amount = sprintf('%.2f', $amount);
        }
        if (!is_string($amount)) {
            return null;
        }
        $minor = self::toMinor($amount);

        return $minor === null ? null : self::fromMinor($minor);
    }

    /**
     * "24.5" → 2450, "24.505" → 2451 (half up at the third decimal), "-1" → null.
     * Null for anything that is not a plain non-negative decimal.
     */
    public static function toMinor(string $amount): ?int
    {
        if (preg_match('/^(\d{1,13})(?:\.(\d*))?$/', trim($amount), $m) !== 1) {
            return null;
        }
        $fraction = str_pad($m[2] ?? '', 3, '0');
        $minor = (int) $m[1] * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$minor;
        }

        return $minor;
    }

    /** 2450 → "24.50". */
    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * An on-chain amount for people: "24500000", 6 → "24.50"; "31250", 8 → "0.0003125".
     * Trailing zeros go, but two decimals stay.
     */
    public static function units(string $amount, int $decimals): string
    {
        if (preg_match('/^\d+$/', $amount) !== 1 || $decimals < 0) {
            return $amount;
        }
        $amount = ltrim($amount, '0');
        if ($decimals === 0) {
            return $amount === '' ? '0' : $amount;
        }
        $amount = str_pad($amount, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($amount, 0, -$decimals);
        $fraction = str_pad(rtrim(substr($amount, -$decimals), '0'), min(2, $decimals), '0');

        return $whole . ($fraction === '' ? '' : '.' . $fraction);
    }
}
