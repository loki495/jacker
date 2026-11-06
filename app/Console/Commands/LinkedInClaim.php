<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LinkedInLink;
use Illuminate\Console\Command;

final class LinkedInClaim extends Command
{
    #[\Override]
    protected $signature = 'linkedin:claim';

    #[\Override]
    protected $description = 'Take the oldest queued LinkedIn link and mark it as being scanned (prints JSON, or nothing when the queue is empty).';

    public function handle(): int
    {
        $link = LinkedInLink::query()->where('status', LinkedInLink::QUEUED)->orderBy('id')->first();
        if (! $link instanceof LinkedInLink) {
            return self::SUCCESS;
        }

        $claimed = LinkedInLink::query()
            ->whereKey($link->id)
            ->where('status', LinkedInLink::QUEUED)
            ->update(['status' => LinkedInLink::SCANNING, 'scan_error' => null]);
        if ($claimed !== 1) {
            return self::SUCCESS;
        }

        $this->line((string) json_encode([
            'id' => $link->id, 'url' => $link->url, 'job_id' => $link->job_id,
            'title' => $link->title, 'company' => $link->company, 'location' => $link->location,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
