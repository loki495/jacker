<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;

/** LaraJobs' home page lists every open posting as a server-rendered card, newest first, with no pagination. */
final readonly class LaraJobs implements JobSource
{
    private const string ROOT = 'https://larajobs.com';

    public function requests(): array
    {
        return [self::ROOT.'/'];
    }

    public function parse(string $body): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$body, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $first = fn (string $query, DOMNode $context): ?DOMNode => ($nodes = $xpath->query($query, $context)) === false ? null : $nodes->item(0);
        $text = fn (string $query, DOMNode $context): ?string => $this->clean((string) $first($query, $context)?->textContent);
        $listings = [];
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " job-link ")]') ?: [] as $card) {
            $path = $card->attributes->getNamedItem('href')?->nodeValue;
            $title = $text('.//p[contains(@class,"text-lg")]', $card);
            // Anything else is page furniture rather than a card, and a card without a title is no use in the review list.
            if (! is_string($path) || preg_match('#^/job/\d+$#', $path) !== 1 || $title === null) {
                continue;
            }
            // The card's second line reads "Full Time - $160,000.00 - $180,000.00", or just "Contractor" when it has no pay.
            $summary = $text('.//p[last()]', $card);
            [$type, $pay] = $summary === null ? [null, null] : array_pad(explode(' - ', $summary, 2), 2, null);
            // The two icon rows: the location, then the posting's age ("2d", "1mo").
            $icons = [];
            foreach ($xpath->query('.//div[svg]', $card) ?: [] as $icon) {
                $icons[] = $this->clean($icon->textContent);
            }
            $location = $icons[0] ?? null;
            $tags = [];
            foreach ($xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " tag ")]', $card) ?: [] as $tag) {
                $tag = $this->clean($tag->textContent);
                // The first tag repeats the employment type already read from the summary line.
                if ($tag !== null && strcasecmp($tag, (string) $type) !== 0) {
                    $tags[] = $tag;
                }
            }
            $employer = $card->attributes->getNamedItem('data-url')?->nodeValue;
            $notes = array_filter([
                $tags === [] ? null : 'Tags: '.implode(', ', $tags),
                is_string($employer) && preg_match('#^https://#i', $employer) === 1 ? 'Apply at: '.$employer : null,
            ]);
            $listings[] = [
                'url' => self::ROOT.$path,
                'title' => $title,
                'company' => $text('.//p[1]', $card),
                'location' => $location,
                'regions' => $this->regions($location),
                'work_arrangement' => $this->arrangement($location),
                'employment_type' => Mapping::employmentType($type),
                'salary_notes' => $pay,
                ...Mapping::usdRange($pay),
                'notes' => $notes === [] ? null : implode("\n", $notes),
                'posted_at' => $this->postedAt($icons[1] ?? null),
            ];
        }

        return $listings;
    }

    public function prefiltered(): bool
    {
        return true;
    }

    public function browserLike(): bool
    {
        return false;
    }

    /** Card text is indented across lines in the source, so whitespace runs collapse to one space. */
    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * "Remote/USA", "Remote / USA Only" and "Remote (UK)" restrict where you may live; a plain "Remote" does not, and an
     * office-based location says nothing about eligibility.
     *
     * @return list<string>|null
     */
    private function regions(?string $location): ?array
    {
        if ($location === null || preg_match('/hybrid|on[- ]?site/i', $location) === 1) {
            return null;
        }
        if (preg_match('/^remote\b(.*)$/i', $location, $match) !== 1) {
            return null;
        }
        $rest = trim((string) preg_replace('/\bonly\b/i', '', $match[1]), " \t\n\r\0\x0B/-()");

        return $rest === '' ? [] : [$rest];
    }

    private function arrangement(?string $location): ?string
    {
        return match (true) {
            $location === null => null,
            preg_match('/hybrid/i', $location) === 1 => 'hybrid',
            preg_match('/^remote\b/i', $location) === 1 => 'remote',
            default => null,
        };
    }

    private function postedAt(?string $age): ?Carbon
    {
        if (preg_match('/^(\d+)\s*(h|d|w|y|mos?|m)$/i', (string) $age, $match) !== 1) {
            return null;
        }
        $now = Carbon::now();
        $count = (int) $match[1];

        return match (strtolower($match[2])) {
            'h' => $now->subHours($count),
            'd' => $now->subDays($count),
            'w' => $now->subWeeks($count),
            'y' => $now->subYears($count),
            default => $now->subMonths($count),
        };
    }
}
