<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/** Agent, model and thinking level for cover letter suggestions, plus the contact lines of the PDF header; saved on the Settings page. */
final class CoverLetterSettings
{
    public const array AGENTS = ['claude', 'codex', 'opencode'];

    public const array THINKING = ['off', 'low', 'medium', 'high'];

    public const string MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/';

    public const array CONTACT = ['name', 'email', 'phone', 'location'];

    /** @return array{agent: string, model: string, thinking: string} */
    public static function ai(): array
    {
        $saved = fn (string $key, string $default): string => is_string($value = Setting::query()->find('cover.'.$key)?->value['v'] ?? null) && $value !== '' ? $value : $default;

        return ['agent' => $saved('agent', (string) config('ai.agent')), 'model' => $saved('model', (string) config('ai.model')), 'thinking' => $saved('thinking', 'off')];
    }

    /** @return array<string, string> the contact fields that are set, keyed by name */
    public static function contact(): array
    {
        $contact = [];
        foreach (Setting::query()->whereIn('key', array_map(fn (string $field): string => 'profile.'.$field, self::CONTACT))->get() as $setting) {
            if (is_string($setting->value['v'] ?? null) && $setting->value['v'] !== '') {
                $contact[substr($setting->key, 8)] = $setting->value['v'];
            }
        }

        return $contact;
    }

    public static function save(string $key, string $value): void
    {
        if ($value === '') {
            Setting::query()->where('key', $key)->delete();

            return;
        }
        Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }
}
