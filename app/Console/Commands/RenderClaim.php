<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimPageRender;
use Illuminate\Console\Command;

final class RenderClaim extends Command
{
    #[\Override]
    protected $signature = 'render:claim';

    #[\Override]
    protected $description = 'Print the oldest queued page render as JSON (id, url) and mark it running; prints nothing when none is queued.';

    public function handle(ClaimPageRender $claim): int
    {
        $request = $claim->handle();
        if ($request !== null) {
            $this->line(json_encode($request, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
