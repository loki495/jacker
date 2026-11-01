<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgentWorker;
use Illuminate\Console\Command;

final class AgentRegister extends Command
{
    #[\Override]
    protected $signature = 'agent:register';

    #[\Override]
    protected $description = 'Heartbeat from the host agent worker: shows the scrape button while its tools are present.';

    public function handle(): int
    {
        AgentWorker::register();

        return self::SUCCESS;
    }
}
