<?php

declare(strict_types=1);

namespace App\Support;

final class UsdPay
{
    /**
     * Converts a row's salary_min/salary_max to US dollars at the fixed rate in config('scrapers.usd_rates') and records the
     * original figures in salary_notes. Rows in USD, without pay, or in a currency with no rate come back unchanged.
     *
     * @param  array<string, mixed>  $row  salary_min, salary_max, currency, pay_period, salary_notes
     * @return array<string, mixed>
     */
    public static function apply(array $row): array
    {
        $currency = strtoupper((string) ($row['currency'] ?? ''));
        $rate = ((array) config('scrapers.usd_rates'))[$currency] ?? null;
        $min = is_numeric($row['salary_min'] ?? null) ? (float) $row['salary_min'] : null;
        $max = is_numeric($row['salary_max'] ?? null) ? (float) $row['salary_max'] : null;
        if ($currency === 'USD' || ! is_numeric($rate) || ($min === null && $max === null)) {
            return $row;
        }
        $amounts = implode('–', array_unique(array_filter([number_format($min ?? $max), $max === null ? null : number_format($max)])));
        $period = ($row['pay_period'] ?? null) ? ' per '.$row['pay_period'] : '';
        $note = "Posted as {$currency} {$amounts}{$period}; converted at about 1 {$currency} = {$rate} USD (fixed rate in config/scrapers.php)";

        return [
            'salary_min' => $min === null ? null : round($min * $rate, 2),
            'salary_max' => $max === null ? null : round($max * $rate, 2),
            'currency' => 'USD',
            'salary_notes' => trim(implode("\n", array_filter([$row['salary_notes'] ?? null, $note]))),
        ] + $row;
    }
}
