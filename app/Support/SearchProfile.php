<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** The "about me and the jobs I want" text served to search sessions, with the settings that shape it. */
final class SearchProfile
{
    public static function dibsId(): ?int
    {
        return config('searchprofile.dibs_id');
    }

    public static function text(): string
    {
        $value = Setting::query()->find('profile.search')?->value['v'] ?? '';

        return is_string($value) ? $value : '';
    }

    public static function source(): string
    {
        $value = Setting::query()->find('profile.search_source')?->value['v'] ?? 'settings';

        return is_string($value) ? $value : 'settings';
    }

    public static function save(string $text, string $source = 'settings'): void
    {
        $text = trim($text);
        DB::transaction(function () use ($text, $source): void {
            if ($text === '') {
                Setting::query()->whereIn('key', ['profile.search', 'profile.search_source'])->delete();

                return;
            }
            Setting::query()->updateOrCreate(['key' => 'profile.search'], ['value' => ['v' => $text]]);
            Setting::query()->updateOrCreate(['key' => 'profile.search_source'], ['value' => ['v' => $source]]);
        });
    }

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $contact = CoverLetterSettings::contact();
        $bands = [];
        foreach ((array) ScoringSettings::get('pay_bands') as $floor => $points) {
            $bands[] = ['annual_usd_at_least' => (int) $floor, 'points' => (int) $points];
        }
        $payload = [
            'about' => self::text(),
            'name' => $contact['name'] ?? null,
            'location' => $contact['location'] ?? null,
            'links' => ProfileLinks::all(),
            'scoring' => [
                'note' => __('api.profile_scoring_note'),
                'skill_rubric' => (string) ScoringSettings::get('rubric'),
                'pay_bands' => $bands,
                'pay_unknown_points' => ScoringSettings::get('pay_unknown'),
                'pay_weight' => ScoringSettings::get('pay_weight'),
                'work_mode_points' => ScoringSettings::get('work_mode'),
                'eastern_only_penalty' => ScoringSettings::get('eastern_penalty'),
            ],
            'accepted_regions' => config('scrapers.allowed_regions'),
        ];

        return [
            'configured' => $payload['about'] !== '',
            'version' => substr(sha1((string) json_encode($payload)), 0, 12),
            'source' => self::source(),
            'updated_at' => Setting::query()->find('profile.search')?->updated_at?->toIso8601String(),
            ...$payload,
        ];
    }
}
