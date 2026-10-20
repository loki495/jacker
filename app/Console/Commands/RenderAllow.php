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
    protected $description = 'Exit 0 when the address is public and may be loaded by the page renderer, 1 when it is private, reserved or not http(s).';

    public function handle(UrlGuard $guard): int
    {
        try {
            $guard->assertPublic((string) $this->argument('url'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
