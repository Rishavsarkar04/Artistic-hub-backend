<?php

namespace App\Support;

/**
 * Exact decimal helpers for rupee amounts with two places, kept as strings ("499.00").
 * Never use floats for money (architecture guide, section 20).
 */
final class Money
{
    private const SCALE = 2;

    /** "499" or "499.5" → "499.50". */
    public static function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }

    /** -1, 0 or 1, like the spaceship operator. */
    public static function compare(string $amount, string $other): int
    {
        return bccomp($amount, $other, self::SCALE);
    }

    public static function add(string $amount, string $other): string
    {
        return bcadd($amount, $other, self::SCALE);
    }

    /** $amount minus $other. */
    public static function subtract(string $amount, string $other): string
    {
        return bcsub($amount, $other, self::SCALE);
    }

    /** Rupees to paise for payment providers, e.g. "899.50" → 89950. Only at the provider boundary. */
    public static function toPaise(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    /** A unit price times a whole quantity. */
    public static function multiply(string $amount, int $quantity): string
    {
        return bcmul($amount, (string) $quantity, self::SCALE);
    }
}
