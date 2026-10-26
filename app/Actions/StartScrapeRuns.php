<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Jobs\ProcessScrapeRun;
use App\Models\ScrapeRun;

final class StartScrapeRuns
{
    /**
     * Queues one run per source, but only when no run is queued or running. Returns how many were queued (0 when refused).
     *
     * @param  list<string>  $sources
     */
    public function handle(array $sources): int
    {
        $sources = array_values(array_intersect($sources, array_keys((array) config('scrapers.sources'))));
        if ($sources === [] || ScrapeRun::active()) {
            return 0;
        }
        foreach ($sources as $source) {
            $run = ScrapeRun::query()->create(['source' => $source, 'status' => LinkCheckStatus::Queued]);
            ProcessScrapeRun::dispatch($run->id);
        }

        return count($sources);
    }
}
