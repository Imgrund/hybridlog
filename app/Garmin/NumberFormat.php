<?php

declare(strict_types=1);

namespace App\Garmin;

/**
 * Numbers in the reader's language: 1,430.5 in English, 1.430,5 in German.
 *
 * A table of separators rather than ext-intl, because that would put a
 * PHP extension between a stranger and their first working dashboard for
 * the sake of two languages. A locale that is not listed reads as English,
 * which is also the source language.
 */
final class NumberFormat
{
    /** locale => [decimal point, thousands separator] */
    private const SEPARATORS = [
        'de' => [',', '.'],
    ];

    public static function format(int|float $value, int $decimals = 0): string
    {
        [$point, $thousands] = self::separators();

        return number_format($value, $decimals, $point, $thousands);
    }

    /**
     * At most this many decimals, with a trailing zero dropped: 39.7 keeps
     * its digit, 69.0 reads as 69. Written against the locale's own decimal
     * point, so it does not quietly stop working in the other language.
     */
    public static function upTo(int|float $value, int $decimals): string
    {
        [$point] = self::separators();
        $formatted = self::format($value, $decimals);

        return str_contains($formatted, $point)
            ? rtrim(rtrim($formatted, '0'), $point)
            : $formatted;
    }

    /** @return array{0: string, 1: string} decimal point, thousands separator */
    private static function separators(): array
    {
        return self::SEPARATORS[app()->getLocale()] ?? ['.', ','];
    }
}
