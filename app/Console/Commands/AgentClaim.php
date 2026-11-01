<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimPendingAiRequest;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class AgentClaim extends Command
{
    #[\Override]
    protected $signature = 'agent:claim';

    #[\Override]
    protected $description = 'Print the oldest queued agent request (scrape first, then apply) as JSON (id, kind, opportunity_id, url) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimPendingAiRequest $claim): int
    {
        $request = $claim->handle(AiRequest::KIND_SCRAPE) ?? $claim->handle(AiRequest::KIND_APPLY);
        if ($request instanceof AiRequest) {
            $this->line(json_encode(['id' => $request->id, 'kind' => $request->kind, 'opportunity_id' => $request->opportunity_id, 'url' => $request->opportunity?->application_url], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
