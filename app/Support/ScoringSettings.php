<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/** Grading criteria: values saved on the Settings page override the defaults in config/scoring.php. */
final class ScoringSettings
{
    public const array KEYS = ['rubric', 'pay_bands', 'pay_unknown', 'work_mode', 'pay_weight', 'eastern_penalty'];

    public static function get(string $key): mixed
    {
        $saved = Setting::query()->find('scoring.'.$key);

        return $saved instanceof Setting ? $saved->value['v'] : config('scoring.'.$key);
    }

    public static function save(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => 'scoring.'.$key], ['value' => ['v' => $value]]);
    }

    public static function reset(): void
    {
        Setting::query()->where('key', 'like', 'scoring.%')->delete();
    }
}
