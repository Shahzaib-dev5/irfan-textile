<?php

namespace App\Modules\Inventory\Support;

use Illuminate\Validation\ValidationException;

final class Decimal
{
    public static function input(mixed $value, int $scale = 6, bool $signed = false): string
    {
        $value = (string) $value;
        if (! preg_match('/^'.($signed ? '-?' : '').'\d{1,12}(?:\.\d{1,'.$scale.'})?$/D', $value)) {
            throw ValidationException::withMessages(['quantity' => 'Enter a decimal with at most '.$scale.' decimal places; scientific notation is not accepted.']);
        }

        return bcadd($value, '0', $scale);
    }

    public static function round(string $value, int $scale = 4): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, bccomp($value, '0', $scale + 8) < 0 ? '-'.$half : $half, $scale);
    }

    public static function negative(string $value, int $scale = 6): string
    {
        return bcsub('0', $value, $scale);
    }
}
