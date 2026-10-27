<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Opportunity;

final class Desirability
{
    public static function score(Opportunity $job, bool $easternOnly): int
    {
        $pay = self::pay($job);
        $mode = (float) (ScoringSettings::get('work_mode')[$job->work_arrangement->value] ?? ScoringSettings::get('work_mode')['unspecified']);
        $weight = (float) ScoringSettings::get('pay_weight');
        $score = $weight * $pay + (1 - $weight) * $mode - ($easternOnly ? (float) ScoringSettings::get('eastern_penalty') : 0);

        return max(1, min(5, (int) round($score)));
    }

    private static function pay(Opportunity $job): float
    {
        $unknown = (float) ScoringSettings::get('pay_unknown');
        $multiplier = config('scoring.annual_multiplier')[$job->pay_period->value ?? ''] ?? null;
        $amounts = array_filter([$job->salary_min, $job->salary_max], fn ($amount): bool => $amount !== null && (float) $amount > 0);
        if ($multiplier === null || $amounts === [] || ($job->currency ?: 'USD') !== 'USD') {
            return $unknown;
        }
        $annual = array_sum(array_map(floatval(...), $amounts)) / count($amounts) * $multiplier;
        foreach (ScoringSettings::get('pay_bands') as $floor => $points) {
            if ($annual >= $floor) {
                return (float) $points;
            }
        }

        return $unknown;
    }
}
