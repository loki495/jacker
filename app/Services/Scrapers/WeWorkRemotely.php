<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

use App\Services\PageText;
use Illuminate\Support\Carbon;

/** We Work Remotely's programming-category RSS feeds (25 items each, no pagination). */
final readonly class WeWorkRemotely implements JobSource
{
    public function __construct(private PageText $text) {}

    public function requests(): array
    {
        return array_values((array) config('scrapers.weworkremotely_feeds'));
    }

    public function parse(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($body, options: LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        $listings = [];
        foreach ($feed->channel->item ?? [] as $item) {
            $title = trim((string) $item->title);
            [$company, $role] = str_contains($title, ': ') ? explode(': ', $title, 2) : [null, $title];
            $listings[] = [
                'url' => trim((string) $item->link),
                'title' => $role,
                'company' => $company,
                // Unrestricted ("Anywhere in the World") becomes an empty region list, i.e. worldwide.
                'regions' => preg_match('/anywhere|worldwide/i', (string) $item->region) === 1 || trim((string) $item->region) === '' ? [] : [trim((string) $item->region)],
                'notes' => trim((string) $item->category) === '' ? null : 'Category: '.trim((string) $item->category),
                'work_arrangement' => 'remote',
                'description' => $this->text->extract(html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5), (int) config('scrapers.max_description_chars')),
                'posted_at' => strtotime((string) $item->pubDate) === false ? null : Carbon::parse((string) $item->pubDate)->utc(),
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
