<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Asks the public job-board APIs of Ashby, Greenhouse and Lever about a posting. Their pages are JavaScript shells that
 * answer 200 even for a closed job, so the API is the only reliable way to tell whether the posting still exists.
 */
final readonly class JobBoardApi
{
    private const string SLUG = '[A-Za-z0-9._-]+';

    public function __construct(private PageText $text) {}

    /**
     * @return array{gone: bool, fields: array<string, mixed>}|null null when the link is not on a known board or the board could not be read
     *
     * @throws ConnectionException
     */
    public function lookup(string $url): ?array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $slug = self::SLUG;

        return match (true) {
            $host === 'jobs.ashbyhq.com' && preg_match("#^/($slug)/([0-9a-f-]{36})/?#i", $path, $m) === 1 => $this->ashby($m[1], strtolower($m[2])),
            in_array($host, ['boards.greenhouse.io', 'job-boards.greenhouse.io'], true) && preg_match("#^/($slug)/jobs/(\d+)#", $path, $m) === 1 => $this->greenhouse($m[1], $m[2]),
            $host === 'jobs.lever.co' && preg_match("#^/($slug)/([0-9a-f-]{36})/?#i", $path, $m) === 1 => $this->lever($m[1], strtolower($m[2])),
            default => null,
        };
    }

    /** @return array{gone: bool, fields: array<string, mixed>}|null */
    private function ashby(string $org, string $id): ?array
    {
        $response = $this->http()->get("https://api.ashbyhq.com/posting-api/job-board/{$org}", ['includeCompensation' => 'true']);
        if (! $response->ok()) {
            return null;
        }
        $job = array_find((array) $response->json('jobs', []), fn (mixed $candidate): bool => is_array($candidate) && strtolower((string) ($candidate['id'] ?? '')) === $id);
        if ($job === null) {
            return ['gone' => true, 'fields' => []];
        }
        $fields = [
            'title' => $this->clean($job['title'] ?? null),
            'location' => $this->clean($job['location'] ?? null),
            'responsibilities' => $this->clean($job['descriptionPlain'] ?? null, 20000),
            'employment_type' => ['FullTime' => 'full_time', 'PartTime' => 'part_time', 'Contract' => 'contract', 'Intern' => 'internship', 'Temporary' => 'temporary'][$job['employmentType'] ?? ''] ?? null,
            'work_arrangement' => ['Remote' => 'remote', 'OnSite' => 'on_site', 'Hybrid' => 'hybrid'][$job['workplaceType'] ?? ''] ?? (($job['isRemote'] ?? false) === true ? 'remote' : null),
        ];
        foreach (($job['compensation']['compensationTiers'][0]['components'] ?? []) as $component) {
            if (($component['compensationType'] ?? null) === 'Salary' && is_numeric($component['minValue'] ?? null)) {
                $fields += [
                    'salary_min' => (string) $component['minValue'], 'salary_max' => is_numeric($component['maxValue'] ?? null) ? (string) $component['maxValue'] : null,
                    'currency' => $component['currencyCode'] ?? null,
                    'pay_period' => ['1 YEAR' => 'year', '1 MONTH' => 'month', '1 WEEK' => 'week', '1 DAY' => 'day', '1 HOUR' => 'hour'][$component['interval'] ?? ''] ?? null,
                ];
                break;
            }
        }

        return ['gone' => false, 'fields' => $this->present($fields)];
    }

    /** @return array{gone: bool, fields: array<string, mixed>}|null */
    private function greenhouse(string $org, string $id): ?array
    {
        $response = $this->http()->get("https://boards-api.greenhouse.io/v1/boards/{$org}/jobs/{$id}", ['pay_transparency' => 'true']);
        if ($response->status() === 404) {
            return ['gone' => true, 'fields' => []];
        }
        if (! $response->ok()) {
            return null;
        }
        $fields = [
            'title' => $this->clean($response->json('title')),
            'company' => $this->clean($response->json('company_name')),
            'location' => $this->clean($response->json('location.name')),
            'responsibilities' => $this->clean($this->text->extract(html_entity_decode((string) $response->json('content', ''), ENT_QUOTES | ENT_HTML5), 20000)),
        ];
        $range = $response->json('pay_input_ranges.0');
        if (is_array($range) && is_numeric($range['min_cents'] ?? null)) {
            $fields += ['salary_min' => (string) ($range['min_cents'] / 100), 'salary_max' => is_numeric($range['max_cents'] ?? null) ? (string) ($range['max_cents'] / 100) : null, 'currency' => $range['currency_type'] ?? null];
        }

        return ['gone' => false, 'fields' => $this->present($fields)];
    }

    /** @return array{gone: bool, fields: array<string, mixed>}|null */
    private function lever(string $org, string $id): ?array
    {
        $response = $this->http()->get("https://api.lever.co/v0/postings/{$org}/{$id}");
        if ($response->status() === 404) {
            return ['gone' => true, 'fields' => []];
        }
        if (! $response->ok()) {
            return null;
        }
        $fields = [
            'title' => $this->clean($response->json('text')),
            'location' => $this->clean($response->json('categories.location')),
            'responsibilities' => $this->clean($response->json('descriptionPlain'), 20000),
            'work_arrangement' => ['remote' => 'remote', 'on-site' => 'on_site', 'hybrid' => 'hybrid'][strtolower((string) $response->json('workplaceType'))] ?? null,
            'employment_type' => ['full-time' => 'full_time', 'part-time' => 'part_time', 'contract' => 'contract', 'intern' => 'internship', 'temporary' => 'temporary'][strtolower((string) $response->json('categories.commitment'))] ?? null,
        ];

        return ['gone' => false, 'fields' => $this->present($fields)];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('linkcheck.timeout'))->withUserAgent((string) config('linkcheck.user_agent'));
    }

    private function clean(mixed $value, int $max = 255): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function present(array $fields): array
    {
        return array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
