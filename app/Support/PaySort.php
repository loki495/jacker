<?php

declare(strict_types=1);

namespace App\Support;

final class PaySort
{
    /**
     * SQL ORDER BY fragment: pay midpoint (or the one bound given) scaled to a year with config('scoring.annual_multiplier'),
     * highest first, unknown pay last. Currencies are not converted.
     */
    public static function orderBy(): string
    {
        $cases = '';
        foreach ((array) config('scoring.annual_multiplier') as $period => $factor) {
            $cases .= " WHEN '".preg_replace('/[^a-z_]/', '', (string) $period)."' THEN ".(float) $factor;
        }
        $annual = "COALESCE((salary_min + salary_max) / 2.0, salary_min, salary_max) * CASE pay_period{$cases} END";

        return "({$annual}) IS NULL, ({$annual}) DESC";
    }
}
