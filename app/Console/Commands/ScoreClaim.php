<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimScoreRequest;
use Illuminate\Console\Command;

final class ScoreClaim extends Command
{
    #[\Override]
    protected $signature = 'score:claim';

    #[\Override]
    protected $description = 'Print the oldest queued scoring request as JSON (id, chain, prompt) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimScoreRequest $claim): int
    {
        $request = $claim->handle();
        if ($request !== null) {
            $this->line(json_encode($request, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
