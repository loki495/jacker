<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UrlGuard;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RenderAllow extends Command
{
    #[\Override]
    protected $signature = 'render:allow {url : Address the host-side browser wants to load}';

    #[\Override]
    protected $description = 'Print the public addresses the page renderer may connect to for this URL (one per line), or exit 1 when it is private, reserved, unresolvable or not http(s).';

    public function handle(UrlGuard $guard): int
    {
        try {
            $addresses = $guard->assertPublic((string) $this->argument('url'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(implode("\n", $addresses));

        return self::SUCCESS;
    }
}
