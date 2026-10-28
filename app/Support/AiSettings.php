<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiDriver;
use App\Models\Setting;
use InvalidArgumentException;

final class AiSettings
{
    public const array FEATURES = ['extract', 'score', 'letter'];

    public const array PROVIDERS = ['anthropic', 'openai'];

    public const string MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D';

    /** @return array{driver: string, provider: string, model: string} */
    public static function feature(string $feature): array
    {
        if (! in_array($feature, self::FEATURES, true)) {
            throw new InvalidArgumentException('Unknown AI feature.');
        }
        $values = [];
        foreach (['driver', 'provider', 'model'] as $field) {
            $value = Setting::query()->find("ai.{$feature}.{$field}")?->value['v'] ?? config("ai.features.{$feature}.{$field}");
            $values[$field] = is_string($value) ? $value : '';
        }

        return $values;
    }

    /** @return array{driver: string, agent: string, model: string} */
    public static function requestAttributes(string $feature, string $bridgeAgent, string $bridgeModel): array
    {
        $settings = self::feature($feature);
        $driver = AiDriver::from($settings['driver']);

        return ['driver' => $driver->value, 'agent' => $driver === AiDriver::Sdk ? $settings['provider'] : $bridgeAgent, 'model' => $driver === AiDriver::Sdk ? $settings['model'] : $bridgeModel];
    }

    public static function validProviderModel(?string $provider, ?string $model): bool
    {
        return in_array($provider, self::PROVIDERS, true) && is_string($model) && strlen($model) <= 100 && preg_match(self::MODEL_PATTERN, $model) === 1;
    }
}
