<?php

declare(strict_types=1);
use App\Services\Scrapers\BuiltIn;
use App\Services\Scrapers\Himalayas;
use App\Services\Scrapers\Wellfound;
use App\Services\Scrapers\WeWorkRemotely;

return [
    // Hard cap on HTTP requests per source per run.
    'max_pages' => (int) env('SCRAPER_MAX_PAGES', 3),
    'spacing' => (int) env('SCRAPER_SPACING', 5),
    'timeout' => (int) env('SCRAPER_TIMEOUT', 20),
    'max_bytes' => (int) env('SCRAPER_MAX_BYTES', 3_000_000),
    'max_description_chars' => 20_000,
    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0 (compatible; JobsTracker personal job finder)'),
    // The HTML sources (Wellfound, Built In) answer only browser-like clients.
    'browser_user_agent' => env('SCRAPER_BROWSER_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'),
    // Listings whose title or text mention none of these are dropped (sources that search server-side skip this).
    'keywords' => ['laravel', 'php'],
    // A listing restricted to specific places is kept only if one of them contains one of these words; unrestricted ones are labelled Worldwide.
    'allowed_regions' => ['united states', 'usa', 'us', 'north america', 'americas', 'worldwide', 'anywhere'],
    // Fixed, approximate US dollars per 1 unit of each currency, used to convert staged pay. Edit them when they drift; a currency not listed is left as posted.
    'usd_rates' => [
        'EUR' => 1.08, 'GBP' => 1.27, 'CAD' => 0.73, 'AUD' => 0.65, 'NZD' => 0.60, 'CHF' => 1.13, 'SEK' => 0.095, 'NOK' => 0.093, 'DKK' => 0.145,
        'PLN' => 0.25, 'CZK' => 0.043, 'HUF' => 0.0027, 'RON' => 0.22, 'BGN' => 0.55, 'RSD' => 0.0092, 'UAH' => 0.024, 'TRY' => 0.03,
        'INR' => 0.012, 'JPY' => 0.0066, 'SGD' => 0.74, 'PHP' => 0.017, 'ILS' => 0.27, 'ZAR' => 0.055, 'EGP' => 0.02, 'NGN' => 0.0007,
        'BRL' => 0.18, 'MXN' => 0.05, 'ARS' => 0.001, 'CLP' => 0.001, 'COP' => 0.00025,
    ],
    'exclude_title' => ['junior', 'intern', 'internship', 'trainee'],
    'sources' => [
        'himalayas' => Himalayas::class,
        'weworkremotely' => WeWorkRemotely::class,
        'wellfound' => Wellfound::class,
        'builtin' => BuiltIn::class,
    ],
    'weworkremotely_feeds' => [
        'https://weworkremotely.com/categories/remote-full-stack-programming-jobs.rss',
        'https://weworkremotely.com/categories/remote-back-end-programming-jobs.rss',
        'https://weworkremotely.com/categories/remote-programming-jobs.rss',
    ],
    'wellfound_pages' => ['https://wellfound.com/role/r/laravel-developer'],
];
