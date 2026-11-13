<?php

namespace App\Support;

final class Money
{
    /** Round to the configured currency precision (PKR uses whole rupees, rounded to 5). */
    public static function round(float $amount, int $step = 5): float
    {
        return (float) (round($amount / $step) * $step);
    }

    public static function format(float $amount, string $currency = 'PKR'): string
    {
        return $currency.' '.number_format($amount, 0);
    }
}
