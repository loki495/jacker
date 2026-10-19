<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RunLinkCheck;
use App\Enums\LinkCheckStatus;
use App\Models\LinkCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessLinkCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $linkCheckId) {}

    public function handle(RunLinkCheck $action): void
    {
        $check = LinkCheck::query()->find($this->linkCheckId);
        if ($check?->status !== LinkCheckStatus::Queued) {
            return;
        }
        try {
            $action->handle($check);
        } catch (Throwable $exception) {
            $check->update(['status' => LinkCheckStatus::Failed, 'message' => __('jobs.link_unexpected'), 'finished_at' => now()]);
            if (config('app.debug')) {
                throw $exception;
            }
            report($exception);
        }
    }
}
