<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

final class EmbeddingSettings
{
    public static function enabled(): bool
    {
        $setting = Setting::query()->find('embeddings.enabled');
        $value = $setting?->value['v'] ?? config('embeddings.enabled', false);

        return $value === true;
    }
}
