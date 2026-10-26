<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

final class Mapping
{
    public static function employmentType(mixed $value): ?string
    {
        $value = strtolower((string) $value);

        return match (true) {
            str_contains($value, 'full') => 'full_time',
            str_contains($value, 'part') => 'part_time',
            str_contains($value, 'contract') => 'contract',
            str_contains($value, 'freelance') => 'freelance',
            str_contains($value, 'intern') => 'internship',
            str_contains($value, 'temp') => 'temporary',
            default => null,
        };
    }

    /**
     * Reads a leading dollar range such as "$100k – $140k" (or "$90,000 - $120,000") as a yearly USD salary.
     *
     * @return array<string, mixed>
     */
    public static function usdRange(mixed $text): array
    {
        if (preg_match('/\$\s*([\d,.]+)\s*([kK])?\s*[–-]\s*\$?\s*([\d,.]+)\s*([kK])?/u', (string) $text, $m) !== 1) {
            return [];
        }
        $amount = fn (string $number, string $suffix): float => (float) str_replace(',', '', $number) * ($suffix === '' ? 1 : 1000);

        return ['salary_min' => $amount($m[1], $m[2]), 'salary_max' => $amount($m[3], $m[4] ?? $m[2]), 'currency' => 'USD', 'pay_period' => 'year'];
    }
}
