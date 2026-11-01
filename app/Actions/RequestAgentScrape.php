<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Support\AgentWorker;

final class RequestAgentScrape
{
    /** Queues one ai-job-search scrape/rank run for the host agent worker; null when the worker is offline or a run is already pending. */
    public function handle(): ?AiRequest
    {
        if (! AgentWorker::online() || $this->pending()) {
            return null;
        }

        return AiRequest::query()->create(['kind' => AiRequest::KIND_SCRAPE, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
    }

    public function pending(): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_SCRAPE)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->where('created_at', '>', now()->subHours(3))->exists();
    }
}
