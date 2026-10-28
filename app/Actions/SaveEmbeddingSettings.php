<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Setting;

final class SaveEmbeddingSettings
{
    public function handle(bool $enabled): void
    {
        Setting::query()->updateOrCreate(['key' => 'embeddings.enabled'], ['value' => ['v' => $enabled]]);
    }
}
