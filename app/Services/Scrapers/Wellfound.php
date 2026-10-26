<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use Illuminate\Support\Carbon;

/** Wellfound's role page: the listings are in the Next.js __NEXT_DATA__ Apollo cache, with no public API. */
final readonly class Wellfound implements JobSource
{
    public function requests(): array
    {
        return array_values((array) config('scrapers.wellfound_pages'));
    }

    public function parse(string $body): array
    {
        if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $body, $match) !== 1) {
            return [];
        }
        $cache = json_decode($match[1], true)['props']['pageProps']['apolloState']['data'] ?? [];
        $companies = [];
        $details = [];
        foreach ($cache as $key => $node) {
            if (! str_starts_with((string) $key, 'StartupResult:') || ! is_array($node)) {
                continue;
            }
            foreach ((array) ($node['highlightedJobListings'] ?? []) as $ref) {
                $companies[$ref['__ref'] ?? ''] = $node['name'] ?? null;
                $details[$ref['__ref'] ?? ''] = $node;
            }
        }
        $listings = [];
        foreach ($cache as $key => $job) {
            if (! str_starts_with((string) $key, 'JobListingSearchResult:') || ! is_array($job) || ! isset($job['id'], $job['slug'])) {
                continue;
            }
            $remote = ($job['remote'] ?? false) === true;
            $places = array_values(array_filter((array) ($remote ? $job['acceptedRemoteLocationNames'] ?? [] : $job['locationNames'] ?? []), is_string(...)));
            $company = $details[$key] ?? [];
            $years = is_numeric($job['yearsExperienceMin'] ?? null) ? $job['yearsExperienceMin'].(is_numeric($job['yearsExperienceMax'] ?? null) ? '–'.$job['yearsExperienceMax'] : '+').' years of experience' : null;
            $notes = array_filter([
                is_string($job['primaryRoleTitle'] ?? null) ? 'Role: '.$job['primaryRoleTitle'] : null,
                is_string($company['highConcept'] ?? null) ? 'Company: '.trim($company['highConcept']) : null,
                preg_match('/^SIZE_(\d+)_(\d+)$/', (string) ($company['companySize'] ?? ''), $size) === 1 ? "Company size: {$size[1]}–{$size[2]} people" : null,
            ]);
            $listings[] = [
                'url' => 'https://wellfound.com/jobs/'.$job['id'].'-'.$job['slug'],
                'title' => $job['title'] ?? null,
                'company' => $companies[$key] ?? null,
                'company_url' => is_string($company['slug'] ?? null) ? 'https://wellfound.com/company/'.rawurlencode($company['slug']) : null,
                'location' => implode(', ', array_filter((array) ($job['locationNames'] ?? []), is_string(...))) ?: null,
                'regions' => $remote ? $places : null,
                'work_arrangement' => $remote ? 'remote' : null,
                'employment_type' => Mapping::employmentType($job['jobType'] ?? null),
                'salary_notes' => $job['compensation'] ?? null,
                ...Mapping::usdRange($job['compensation'] ?? null),
                'requirements' => $years,
                'notes' => implode("\n", $notes) ?: null,
                'description' => is_string($job['description'] ?? null) ? mb_substr($job['description'], 0, (int) config('scrapers.max_description_chars')) : null,
                'posted_at' => is_numeric($job['liveStartAt'] ?? null) ? Carbon::createFromTimestampUTC((int) $job['liveStartAt']) : null,
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
        return true;
    }
}
