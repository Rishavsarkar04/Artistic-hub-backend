<?php

namespace App\Support;

final class Currency
{
    /** Symbols for the currencies the shop uses. Add one here before using a new currency. */
    private const SYMBOLS = [
        'INR' => '₹',
    ];

    /** The shop's currency code (config app.currency): every product price is in it. */
    public static function shop(): string
    {
        return config('app.currency');
    }

    /**
     * The two currency fields every response puts next to its amounts. Defaults to the shop currency (catalog,
     * cart); pass an order's or payment's own code for those.
     *
     * @return array{currency: string, currency_symbol: string}
     */
    public static function fields(?string $code = null): array
    {
        $code ??= self::shop();

        return ['currency' => $code, 'currency_symbol' => self::symbol($code)];
    }

    /**
     * The display symbol for an ISO 4217 code, e.g. "INR" → "₹". Derived, never stored: the code is the data.
     * An unknown code is returned as is ("USD"), so there is always something to show.
     */
    public static function symbol(string $code): string
    {
        return self::SYMBOLS[strtoupper($code)] ?? strtoupper($code);
    }
}
