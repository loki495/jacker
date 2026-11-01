<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Support\AgentWorker;

final class RequestAgentApply
{
    /** Queues the /apply workflow for one selected job; null when the worker is offline, the job has no http(s) application link, or a run is already pending. */
    public function handle(Opportunity $opportunity): ?AiRequest
    {
        if (! AgentWorker::online() || ! $this->eligible($opportunity) || $this->pending($opportunity)) {
            return null;
        }

        return AiRequest::query()->create(['kind' => AiRequest::KIND_APPLY, 'opportunity_id' => $opportunity->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
    }

    public function eligible(Opportunity $opportunity): bool
    {
        return preg_match('#^https?://[^\s"\'<>`\\\\]+$#i', (string) $opportunity->application_url) === 1;
    }

    public function pending(Opportunity $opportunity): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_APPLY)->where('opportunity_id', $opportunity->id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->where('created_at', '>', now()->subHours(3))->exists();
    }
}
