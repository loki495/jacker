<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimLetterRequest;
use Illuminate\Console\Command;

final class LetterClaim extends Command
{
    #[\Override]
    protected $signature = 'letter:claim';

    #[\Override]
    protected $description = 'Print the oldest queued cover letter request as JSON (id, agent, model, thinking, prompt) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimLetterRequest $claim): int
    {
        $request = $claim->handle();
        if ($request !== null) {
            $this->line(json_encode($request, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
