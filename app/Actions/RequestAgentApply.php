<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Services\UrlGuard;
use App\Support\AgentWorker;
use InvalidArgumentException;

final readonly class RequestAgentApply
{
    public function __construct(private UrlGuard $guard) {}

    /** Queues the /apply workflow for one selected job; null when the worker is offline, the job has no http(s) application link, or a run is already pending. */
    public function handle(Opportunity $opportunity): ?AiRequest
    {
        if (! AgentWorker::online() || ! $this->eligible($opportunity) || $this->pending($opportunity) || ! $this->isPublic($opportunity)) {
            return null;
        }

        return AiRequest::query()->create(['kind' => AiRequest::KIND_APPLY, 'opportunity_id' => $opportunity->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
    }

    public function eligible(Opportunity $opportunity): bool
    {
        $url = (string) $opportunity->application_url;

        return strlen($url) <= 500 && preg_match('#^https?://[^\s"\'<>`\\\\\#]+$#i', $url) === 1;
    }

    /** True when the link is well formed and resolves only to public addresses (the same guard the page renderer uses). */
    public function isPublic(Opportunity $opportunity): bool
    {
        if (! $this->eligible($opportunity)) {
            return false;
        }
        try {
            $this->guard->assertPublic((string) $opportunity->application_url);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public function pending(Opportunity $opportunity): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_APPLY)->where('opportunity_id', $opportunity->id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->where('created_at', '>', now()->subHours(3))->exists();
    }
}
