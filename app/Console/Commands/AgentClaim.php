<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimPendingAiRequest;
use App\Actions\RequestAgentApply;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use Illuminate\Console\Command;

final class AgentClaim extends Command
{
    #[\Override]
    protected $signature = 'agent:claim';

    #[\Override]
    protected $description = 'Print the oldest queued agent request (scrape first, then apply, then fill) as JSON (id, kind, opportunity_id, url, company, title) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimPendingAiRequest $claim, RequestAgentApply $apply): int
    {
        while (($request = $claim->handle(AiRequest::KIND_SCRAPE) ?? $claim->handle(AiRequest::KIND_APPLY) ?? $claim->handle(AiRequest::KIND_FILL)) instanceof AiRequest) {
            if (in_array($request->kind, [AiRequest::KIND_APPLY, AiRequest::KIND_FILL], true) && (! $request->opportunity instanceof Opportunity || ! $apply->isPublic($request->opportunity))) {
                $request->update(['status' => LinkCheckStatus::Failed, 'message' => __('jobs.apply_bad_link'), 'finished_at' => now()]);

                continue;
            }
            $this->line(json_encode(['id' => $request->id, 'kind' => $request->kind, 'opportunity_id' => $request->opportunity_id, 'url' => $request->opportunity?->application_url, 'company' => $request->opportunity?->company, 'title' => $request->opportunity?->title], JSON_THROW_ON_ERROR));

            break;
        }

        return self::SUCCESS;
    }
}
