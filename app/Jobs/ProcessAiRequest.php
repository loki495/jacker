<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RunAiRequest;
use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessAiRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public int $aiRequestId) {}

    public function handle(RunAiRequest $action): void
    {
        $action->handle($this->aiRequestId);
    }

    public function failed(?Throwable $exception): void
    {
        AiRequest::query()->whereKey($this->aiRequestId)->where('driver', AiDriver::Sdk->value)
            ->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])
            ->update(['status' => LinkCheckStatus::Failed->value, 'message' => __('ai.unexpected'), 'finished_at' => now()]);
    }
}
