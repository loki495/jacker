<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimAiRequest;
use Illuminate\Console\Command;

final class AiClaim extends Command
{
    #[\Override]
    protected $signature = 'ai:claim';

    #[\Override]
    protected $description = 'Print the oldest queued AI request as JSON (id, agent chain, prompt) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimAiRequest $claim): int
    {
        $request = $claim->handle();
        if ($request !== null) {
            $this->line(json_encode($request, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
