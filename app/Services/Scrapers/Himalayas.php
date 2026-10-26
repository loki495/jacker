<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\Services\PageText;
use Illuminate\Support\Carbon;

/** Himalayas' public search API (20 results per page). */
final readonly class Himalayas implements JobSource
{
    private const string API = 'https://himalayas.app/jobs/api/search';

    // More than this many UTC offsets means no real time-zone restriction.
    private const int MAX_RESTRICTED_ZONES = 12;

    public function __construct(private PageText $text) {}

    public function requests(): array
    {
        $urls = [];
        for ($page = 1; $page <= (int) config('scrapers.max_pages'); $page++) {
            foreach ((array) config('scrapers.keywords') as $keyword) {
                $urls[] = self::API.'?'.http_build_query(['q' => $keyword, 'page' => $page]);
            }
        }

        return $urls;
    }

    public function parse(string $body): array
    {
        $listings = [];
        foreach ((array) (json_decode($body, true)['jobs'] ?? []) as $job) {
            if (! is_array($job) || ! is_string($job['guid'] ?? null)) {
                continue;
            }
            $countries = array_values(array_filter((array) ($job['locationRestrictions'] ?? []), is_string(...)));
            $zones = array_values(array_filter((array) ($job['timezoneRestrictions'] ?? []), is_numeric(...)));
            $restrictions = array_filter([
                $countries === [] ? null : 'Eligible countries: '.implode(', ', $countries),
                $zones !== [] && count($zones) <= self::MAX_RESTRICTED_ZONES ? 'Time zones (UTC offsets): '.implode(', ', $zones) : null,
            ]);
            $notes = array_filter([
                ($seniority = array_filter((array) ($job['seniority'] ?? []), is_string(...))) === [] ? null : 'Seniority: '.implode(', ', $seniority),
                is_string($job['employmentType'] ?? null) ? 'Employment type: '.$job['employmentType'] : null,
            ]);
            $listings[] = [
                'url' => $job['guid'],
                'title' => $job['title'] ?? null,
                'company' => $job['companyName'] ?? null,
                'company_url' => is_string($job['companySlug'] ?? null) ? 'https://himalayas.app/companies/'.rawurlencode($job['companySlug']) : null,
                'regions' => $countries,
                'remote_restrictions' => $restrictions === [] ? null : implode("\n", $restrictions),
                'work_arrangement' => 'remote',
                'employment_type' => Mapping::employmentType($job['employmentType'] ?? null),
                'salary_min' => $job['minSalary'] ?? null,
                'salary_max' => $job['maxSalary'] ?? null,
                'currency' => $job['currency'] ?? null,
                'pay_period' => ['annual' => 'year', 'monthly' => 'month', 'weekly' => 'week', 'daily' => 'day', 'hourly' => 'hour'][strtolower((string) ($job['salaryPeriod'] ?? ''))] ?? null,
                'description' => $this->text->extract((string) ($job['description'] ?? ''), (int) config('scrapers.max_description_chars')),
                'notes' => implode("\n", $notes) ?: null,
                'posted_at' => is_numeric($job['pubDate'] ?? null) ? Carbon::createFromTimestampUTC((int) $job['pubDate']) : null,
                'deadline' => is_numeric($job['expiryDate'] ?? null) ? Carbon::createFromTimestampUTC((int) $job['expiryDate'])->toDateString() : null,
            ];
        }

        return $listings;
    }

    public function prefiltered(): bool
    {
        return false;
    }

    public function browserLike(): bool
    {
        return false;
    }
}
