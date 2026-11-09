<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EnrichListings;
use Illuminate\Console\Command;

final class ListingsEnrich extends Command
{
    #[\Override]
    protected $signature = 'listings:enrich {--limit=5 : How many listings to check, 1 to 50} {--dry-run : List the candidates without queueing anything}';

    #[\Override]
    protected $description = 'Queue link checks that fill empty fields (and an AI read of the page) for listings with gaps; reports dead links without closing them.';

    public function handle(EnrichListings $enrich): int
    {
        $limit = (string) $this->option('limit');
        if (preg_match('/^\d+$/', $limit) !== 1 || (int) $limit < 1 || (int) $limit > 50) {
            $this->error(__('jobs.enrich_bad_limit'));

            return self::FAILURE;
        }
        $result = $enrich->handle((int) $limit, (bool) $this->option('dry-run'));

        $this->line(__('jobs.enrich_summary', ['candidates' => count($result['candidates']), 'queued' => $result['queued'], 'linkedin' => $result['skipped_linkedin']]));
        foreach ($result['candidates'] as $opportunity) {
            $this->line("  #{$opportunity->id} {$opportunity->company} - {$opportunity->title}");
        }
        if ($result['gone']->isNotEmpty()) {
            $this->newLine();
            $this->line(__('jobs.enrich_gone_heading'));
            foreach ($result['gone'] as $opportunity) {
                $this->line("  #{$opportunity->id} {$opportunity->company}");
                $this->line("      {$opportunity->title}");
            }
        }

        return self::SUCCESS;
    }
}
