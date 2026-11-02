<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgentWorker;
use Illuminate\Console\Command;

final class AgentRegister extends Command
{
    #[\Override]
    protected $signature = 'agent:register {--general= : State of the general resume copy: ok or missing}';

    #[\Override]
    protected $description = 'Heartbeat from the host agent worker: shows the scrape button while its tools are present.';

    public function handle(): int
    {
        AgentWorker::register();
        if (in_array($this->option('general'), ['ok', 'missing'], true)) {
            AgentWorker::setGeneralResume($this->option('general') === 'ok');
        }

        return self::SUCCESS;
    }
}
