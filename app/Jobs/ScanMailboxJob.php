<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ScanMailbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ScanMailboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(ScanMailbox $scan): void
    {
        $scan->handle();
    }
}
