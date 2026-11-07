<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Support\AgentWorker;

final readonly class RequestAgentFill
{
    public function __construct(private RequestAgentApply $apply) {}

    /** Queues opening the job's application form in a visible window; null when the worker is offline, the link is unconfirmed or not a public http(s) address, or one is already pending. */
    public function handle(Opportunity $opportunity): ?AiRequest
    {
        if (! AgentWorker::online() || $this->apply->needsLinkConfirmation($opportunity) || ! $this->apply->isPublic($opportunity) || $this->pending($opportunity)) {
            return null;
        }

        return AiRequest::query()->create(['kind' => AiRequest::KIND_FILL, 'opportunity_id' => $opportunity->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
    }

    public function pending(Opportunity $opportunity): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_FILL)->where('opportunity_id', $opportunity->id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->where('created_at', '>', now()->subHours(3))->exists();
    }
}
