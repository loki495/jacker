<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LinkedInLink;
use Illuminate\Console\Command;

final class LinkedInLinks extends Command
{
    #[\Override]
    protected $signature = 'linkedin:links {--json : Print one JSON object per link instead of a table} {--status=new : Which links to list: new, imported, dismissed or all}';

    #[\Override]
    protected $description = 'List the LinkedIn job links kept from alert emails, for a tool that can read LinkedIn with a signed-in browser.';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $query = LinkedInLink::query()->orderBy('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $links = $query->get(['id', 'job_id', 'url', 'title', 'company', 'location', 'alert', 'status', 'created_at']);

        if ($this->option('json')) {
            foreach ($links as $link) {
                $this->line((string) json_encode([
                    'id' => $link->id, 'job_id' => $link->job_id, 'url' => $link->url, 'title' => $link->title,
                    'company' => $link->company, 'location' => $link->location, 'alert' => $link->alert,
                    'status' => $link->status, 'saved_at' => $link->created_at?->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'title', 'company', 'location', 'alert', 'status'],
            $links->map(fn (LinkedInLink $link): array => [$link->id, $link->title, $link->company, $link->location, $link->alert, $link->status])->all(),
        );

        return self::SUCCESS;
    }
}
