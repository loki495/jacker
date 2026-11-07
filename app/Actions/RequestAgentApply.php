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
    /** Extra-field key set when the AI took the application link from the posting text; cleared when the owner confirms it. */
    public const string UNCONFIRMED = 'Apply link source';

    public function __construct(private UrlGuard $guard) {}

    public function needsLinkConfirmation(Opportunity $opportunity): bool
    {
        return isset($opportunity->extra_fields[self::UNCONFIRMED]);
    }

    public function confirmLink(Opportunity $opportunity): void
    {
        $extra = $opportunity->extra_fields ?? [];
        unset($extra[self::UNCONFIRMED]);
        $opportunity->update(['extra_fields' => $extra === [] ? null : $extra]);
    }

    /** Queues the /apply workflow for one selected job; null when the worker is offline, the job has no http(s) application link, or a run is already pending. */
    public function handle(Opportunity $opportunity): ?AiRequest
    {
        if (! AgentWorker::online() || $this->needsLinkConfirmation($opportunity) || ! $this->eligible($opportunity) || $this->pending($opportunity) || ! $this->isPublic($opportunity)) {
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
