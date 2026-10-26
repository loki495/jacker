<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use DOMDocument;
use DOMNode;
use DOMXPath;

/** Built In's remote search pages: server-rendered job cards. */
final readonly class BuiltIn implements JobSource
{
    private const string ROOT = 'https://builtin.com';

    public function requests(): array
    {
        $urls = [];
        for ($page = 1; $page <= (int) config('scrapers.max_pages'); $page++) {
            foreach ((array) config('scrapers.keywords') as $keyword) {
                $urls[] = self::ROOT.'/jobs/remote?'.http_build_query(['search' => $keyword, 'page' => $page]);
            }
        }

        return $urls;
    }

    public function parse(string $body): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$body, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $first = fn (string $query, DOMNode $context): ?DOMNode => ($nodes = $xpath->query($query, $context)) === false ? null : $nodes->item(0);
        $text = fn (string $query, DOMNode $context): ?string => ($value = trim((string) $first($query, $context)?->textContent)) === '' ? null : $value;
        $listings = [];
        foreach ($xpath->query('//div[@data-id="job-card"]') ?: [] as $card) {
            $link = $first('.//a[@data-id="job-card-title"]', $card);
            $path = $link?->attributes?->getNamedItem('data-alias')?->nodeValue;
            if (! $link instanceof DOMNode || ! is_string($path) || ! str_starts_with($path, '/job/')) {
                continue;
            }
            $skills = [];
            foreach ($xpath->query('.//span[contains(@class,"mx-sm")]', $card) ?: [] as $skill) {
                $skills[] = trim($skill->textContent);
            }
            $companyPath = $first('.//a[@data-id="company-title"]', $card)?->attributes?->getNamedItem('href')?->nodeValue;
            $pay = $text('.//i[contains(@class,"fa-sack-dollar")]/ancestor::div[1]/following-sibling::span[1]', $card);
            $level = $text('.//i[contains(@class,"fa-trophy")]/ancestor::div[1]/following-sibling::span[1]', $card);
            $listings[] = [
                'url' => self::ROOT.$path,
                'title' => trim($link->textContent),
                'company' => $text('.//a[@data-id="company-title"]', $card),
                'company_url' => is_string($companyPath) && str_starts_with($companyPath, '/company/') ? self::ROOT.$companyPath : null,
                'location' => $text('.//i[contains(@class,"fa-location-dot")]/ancestor::div[1]/following-sibling::div[1]', $card),
                'work_arrangement' => 'remote',
                'salary_notes' => $pay,
                ...$this->salary($pay),
                'description' => $text('.//div[contains(@class,"fs-sm") and contains(@class,"mb-md")]', $card),
                'requirements' => $skills === [] ? null : 'Top skills: '.implode(', ', array_unique($skills)),
                'notes' => $level === null ? null : 'Level: '.$level,
            ];
        }

        return $listings;
    }

    /**
     * Built In prints pay as e.g. "110K-130K Annually" (US dollars).
     *
     * @return array<string, mixed>
     */
    private function salary(?string $pay): array
    {
        if (preg_match('/([\d.]+)\s*([kK])?\s*-\s*([\d.]+)\s*([kK])?\s*(Annually|Hourly|Monthly)/', (string) $pay, $m) !== 1) {
            return [];
        }
        $amount = fn (string $number, string $suffix): float => (float) $number * ($suffix === '' ? 1 : 1000);

        return [
            'salary_min' => $amount($m[1], $m[2]), 'salary_max' => $amount($m[3], $m[4] ?: $m[2]), 'currency' => 'USD',
            'pay_period' => ['Annually' => 'year', 'Hourly' => 'hour', 'Monthly' => 'month'][$m[5]],
        ];
    }

    public function prefiltered(): bool
    {
        return true;
    }

    public function browserLike(): bool
    {
        return true;
    }
}
