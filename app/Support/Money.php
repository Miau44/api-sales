<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const MAX_MINOR_UNITS = 9_999_999_999;

    public static function toMinorUnits(string|int $amount): int
    {
        $amount = (string) $amount;

        if (! preg_match('/^(0|[1-9]\d{0,7})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('El importe no cumple con la precisión decimal esperada.');
        }

        $wholeUnits = (int) $matches[1];
        $fractionalUnits = (int) str_pad($matches[2] ?? '', 2, '0');

        return ($wholeUnits * 100) + $fractionalUnits;
    }

    public static function toDecimalString(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function toApiNumber(int $minorUnits): int|float
    {
        if ($minorUnits % 100 === 0) {
            return intdiv($minorUnits, 100);
        }

        return $minorUnits / 100;
    }
}
