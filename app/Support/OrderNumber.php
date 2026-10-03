<?php

namespace App\Support;

use Closure;
use DateTimeInterface;

final class OrderNumber
{
    /** No 0/O or 1/I, so a number can be read out over the phone. */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const PREFIX = 'ORD';

    private const SUFFIX_LENGTH = 6;

    /** Regex for a valid order number, e.g. for route constraints. */
    public const PATTERN = 'ORD-[0-9]{8}-[2-9A-HJ-NP-Z]{6}';

    /**
     * "ORD-20261003-7K2Q9M": the date plus a random suffix, drawn again until $taken says it is free.
     * The unique index on orders.order_number is the final guarantee.
     *
     * @param  Closure(string): bool  $taken
     */
    public static function unique(DateTimeInterface $date, Closure $taken): string
    {
        do {
            $orderNumber = self::PREFIX.'-'.$date->format('Ymd').'-'.self::randomSuffix();
        } while ($taken($orderNumber));

        return $orderNumber;
    }

    private static function randomSuffix(): string
    {
        $suffix = '';
        for ($position = 0; $position < self::SUFFIX_LENGTH; $position++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $suffix;
    }
}
