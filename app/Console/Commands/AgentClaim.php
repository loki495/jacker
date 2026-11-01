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
    protected $description = 'Print the id of the oldest queued agent scrape request and mark it running; prints nothing when none is queued.';

    public function handle(ClaimPendingAiRequest $claim): int
    {
        $request = $claim->handle(AiRequest::KIND_SCRAPE);
        if ($request instanceof AiRequest) {
            $this->line((string) $request->id);
        }

        return self::SUCCESS;
    }
}
