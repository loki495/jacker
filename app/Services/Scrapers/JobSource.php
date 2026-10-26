<?php

declare(strict_types=1);

namespace App\Services\Scrapers;

/**
 * One job site. requests() lists the URLs to fetch (the runner caps and spaces them); parse() turns one response body into
 * listings keyed like the found_jobs columns (url is required, the rest optional).
 */
interface JobSource
{
    /** @return list<string> */
    public function requests(): array;

    /** @return list<array<string, mixed>> */
    public function parse(string $body): array;

    /** True for sources that search server-side, so the keyword filter is skipped. */
    public function prefiltered(): bool;

    /** True for sources that answer only browser-like clients. */
    public function browserLike(): bool;
}
