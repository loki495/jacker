<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\FoundJob;
use App\Models\Opportunity;
use App\Models\ScrapeRun;
use App\Services\Scrapers\JobSource;
use App\Support\ApplicationUrl;
use App\Support\UsdPay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

final class RunScrape
{
    private const array ENUM_FIELDS = [
        'work_arrangement' => ['remote', 'on_site', 'hybrid'],
        'employment_type' => ['full_time', 'part_time', 'contract', 'freelance', 'temporary', 'internship'],
        'pay_period' => ['hour', 'day', 'week', 'month', 'year'],
    ];

    public function handle(ScrapeRun $run): void
    {
        $source = app((string) config('scrapers.sources.'.$run->source));
        assert($source instanceof JobSource);
        $requests = array_slice($source->requests(), 0, max(1, (int) config('scrapers.max_pages')));
        $run->update(['status' => LinkCheckStatus::Running, 'pages_total' => count($requests), 'started_at' => now()]);

        foreach ($requests as $index => $url) {
            if ($index > 0) {
                Sleep::for((int) config('scrapers.spacing'))->seconds();
            }
            try {
                $response = Http::timeout((int) config('scrapers.timeout'))
                    ->withUserAgent((string) config($source->browserLike() ? 'scrapers.browser_user_agent' : 'scrapers.user_agent'))
                    ->get($url);
            } catch (ConnectionException) {
                $this->finish($run, LinkCheckStatus::Failed, __('scrapers.unreachable'));

                return;
            }
            // Any refusal ends the run at once instead of retrying the other pages: blocks and rate limits are not argued with.
            if (! $response->successful()) {
                $this->finish($run, LinkCheckStatus::Failed, __('scrapers.refused_http', ['status' => $response->status()]));

                return;
            }
            if (strlen($response->body()) > (int) config('scrapers.max_bytes')) {
                $this->finish($run, LinkCheckStatus::Failed, __('scrapers.too_big'));

                return;
            }
            foreach ($source->parse($response->body()) as $listing) {
                $run->seen++;
                $run->added += $this->store($run, $source, $listing) ? 1 : 0;
            }
            $run->pages_done++;
            $run->save();
        }
        $this->finish($run, LinkCheckStatus::Done, null);
    }

    /** @param array<string, mixed> $listing */
    private function store(ScrapeRun $run, JobSource $source, array $listing): bool
    {
        $url = is_string($listing['url'] ?? null) ? trim($listing['url']) : '';
        $normalized = ApplicationUrl::normalize($url);
        if ($normalized === null || ! preg_match('#^https://#i', $url) || mb_strlen($url) > 2048) {
            return false;
        }
        $title = $this->text($listing['title'] ?? null);
        if ($this->excluded($title) || (! $source->prefiltered() && ! $this->matches($title.' '.$this->text($listing['description'] ?? null, 20000)))) {
            return false;
        }
        $regions = is_array($listing['regions'] ?? null) ? array_values(array_filter($listing['regions'], is_string(...))) : null;
        if ($regions !== null && $regions !== [] && ! $this->usEligible($regions)) {
            return false;
        }
        if (FoundJob::query()->where('normalized_url', $normalized)->exists() || Opportunity::query()->where('normalized_application_url', $normalized)->exists()) {
            return false;
        }
        $row = [
            'scrape_run_id' => $run->id, 'source' => $run->source, 'url' => $url, 'normalized_url' => $normalized, 'title' => $title,
            'company' => $this->text($listing['company'] ?? null),
            'location' => $regions === [] ? __('scrapers.worldwide') : ($regions === null ? $this->text($listing['location'] ?? null, 2000) : $this->regionLabel($regions)),
            'company_url' => $this->link($listing['company_url'] ?? null),
            'remote_restrictions' => $this->text($listing['remote_restrictions'] ?? null, 5000),
            'requirements' => $this->text($listing['requirements'] ?? null, 5000), 'notes' => $this->text($listing['notes'] ?? null, 5000),
            'deadline' => is_string($listing['deadline'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $listing['deadline']) === 1 ? $listing['deadline'] : null,
            'salary_notes' => $this->text($listing['salary_notes'] ?? null, 2000),
            'description' => $this->text($listing['description'] ?? null, (int) config('scrapers.max_description_chars')),
            'posted_at' => $listing['posted_at'] ?? null,
        ];
        foreach (self::ENUM_FIELDS as $field => $allowed) {
            $row[$field] = in_array($listing[$field] ?? null, $allowed, true) ? $listing[$field] : null;
        }
        foreach (['salary_min', 'salary_max'] as $field) {
            $row[$field] = is_numeric($listing[$field] ?? null) && $listing[$field] >= 0 && $listing[$field] < 1e10 ? round((float) $listing[$field], 2) : null;
        }
        $currency = strtoupper((string) ($listing['currency'] ?? ''));
        $row['currency'] = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
        FoundJob::query()->create(UsdPay::apply($row));

        return true;
    }

    /** @param list<string> $regions */
    private function usEligible(array $regions): bool
    {
        return array_any($regions, fn (string $region): bool => $this->allowedRegion($region));
    }

    private function allowedRegion(string $region): bool
    {
        return array_any((array) config('scrapers.allowed_regions'), fn (string $word): bool => preg_match('/\b'.preg_quote($word, '/').'\b/i', $region) === 1);
    }

    /** @param list<string> $regions */
    private function regionLabel(array $regions): string
    {
        $shown = array_find($regions, $this->allowedRegion(...)) ?? $regions[0];

        return count($regions) > 1 ? $shown.' +'.(count($regions) - 1) : $shown;
    }

    private function link(mixed $value): ?string
    {
        $value = $this->text($value, 2048);

        return $value !== null && preg_match('#^https://#i', $value) === 1 ? $value : null;
    }

    private function matches(string $text): bool
    {
        return array_any((array) config('scrapers.keywords'), fn (string $keyword): bool => preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $text) === 1);
    }

    private function excluded(?string $title): bool
    {
        return $title !== null && array_any((array) config('scrapers.exclude_title'), fn (string $word): bool => preg_match('/\b'.preg_quote($word, '/').'\b/i', $title) === 1);
    }

    private function text(mixed $value, int $max = 255): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function finish(ScrapeRun $run, LinkCheckStatus $status, ?string $message): void
    {
        $run->update(['status' => $status, 'message' => $message, 'finished_at' => now()]);
    }
}
