<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/** Optional public profile links shown in the app shell; saved from the Settings page. */
final class ProfileLinks
{
    public const array SITES = ['linkedin' => 'linkedin.com', 'github' => 'github.com'];

    /** @return array<string, string> only the links that are set, keyed by site */
    public static function all(): array
    {
        $links = [];
        foreach (Setting::query()->whereIn('key', array_map(fn (string $site): string => 'profile.'.$site, array_keys(self::SITES)))->get() as $setting) {
            if (is_string($setting->value['v'] ?? null) && $setting->value['v'] !== '') {
                $links[substr($setting->key, 8)] = $setting->value['v'];
            }
        }

        return $links;
    }

    public static function save(string $site, string $url): void
    {
        if ($url === '') {
            Setting::query()->where('key', 'profile.'.$site)->delete();

            return;
        }
        Setting::query()->updateOrCreate(['key' => 'profile.'.$site], ['value' => ['v' => $url]]);
    }
}
