<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ScanMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class MailScan extends Command
{
    #[\Override]
    protected $signature = 'mail:scan {--dry-run : Read the mailbox and list the links that would be queued, without recording or queuing anything}';

    #[\Override]
    protected $description = 'Read the configured mailbox (read-only) and queue link checks for job links in new emails.';

    public function handle(ScanMailbox $scan): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $scan->handle($dryRun);
        $this->info(__('jobs.mail_scanned', Arr::only($result, ['messages', 'found', 'queued'])));
        if ($dryRun) {
            $this->line(__('jobs.mail_dry_run'));
            array_map($this->line(...), $result['urls']);
        }

        return self::SUCCESS;
    }
}
