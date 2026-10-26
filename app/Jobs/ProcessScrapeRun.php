<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RunScrape;
use App\Enums\LinkCheckStatus;
use App\Models\ScrapeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessScrapeRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Up to max_pages requests, each followed by the configured spacing.
    public int $timeout = 300;

    public function __construct(public int $scrapeRunId) {}

    public function handle(RunScrape $action): void
    {
        $run = ScrapeRun::query()->find($this->scrapeRunId);
        if ($run?->status !== LinkCheckStatus::Queued) {
            return;
        }
        try {
            $action->handle($run);
        } catch (Throwable $exception) {
            $run->update(['status' => LinkCheckStatus::Failed, 'message' => __('scrapers.unexpected'), 'finished_at' => now()]);
            if (config('app.debug')) {
                throw $exception;
            }
            report($exception);
        }
    }
}
