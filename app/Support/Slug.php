<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

final class Slug
{
    /**
     * "Winter Spice" → "winter-spice", then "winter-spice-2", "-3"… until $taken says it is free.
     *
     * @param  Closure(string): bool  $taken
     */
    public static function unique(string $text, Closure $taken): string
    {
        $base = Str::slug($text) ?: 'item';
        $slug = $base;

        for ($suffix = 2; $taken($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
