<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PreloadApplicationMarkdown;
use App\Models\Opportunity;
use Illuminate\Console\Command;

final class AgentPreload extends Command
{
    #[\Override]
    protected $signature = 'agent:preload {opportunity : Opportunity id}';

    #[\Override]
    protected $description = 'Fill a job\'s empty letter and resume editors from the Markdown the agent worker stored; never overwrites existing text.';

    public function handle(PreloadApplicationMarkdown $preload): int
    {
        $job = Opportunity::query()->find((int) $this->argument('opportunity'));
        if (! $job instanceof Opportunity) {
            $this->error('No such opportunity.');

            return self::FAILURE;
        }
        $this->line(implode(',', $preload->handle($job)));

        return self::SUCCESS;
    }
}
