<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SaveOpportunity;
use App\Models\LinkedInLink;
use App\Models\Opportunity;
use App\Support\ApplicationUrl;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class LinkedInComplete extends Command
{
    #[\Override]
    protected $signature = 'linkedin:complete {id} {--json= : JSON with title, company, location, description and apply_url} {--fail= : Why the scan could not read the page}';

    #[\Override]
    protected $description = 'Record what a browser scan read from one LinkedIn link: create or update the listing and mark the link imported, or mark it failed.';

    public function handle(SaveOpportunity $save): int
    {
        $link = LinkedInLink::query()->find((int) $this->argument('id'));
        if (! $link instanceof LinkedInLink) {
            $this->line((string) json_encode(['ok' => false, 'error' => 'unknown link']));

            return self::FAILURE;
        }

        $failed = $this->option('fail');
        if (is_string($failed) && $failed !== '') {
            return $this->markFailed($link, $failed);
        }

        $data = json_decode((string) $this->option('json'), true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || trim($data['title']) === '' || ! is_string($data['company'] ?? null) || trim($data['company']) === '') {
            return $this->markFailed($link, 'the page did not show a job title and company');
        }

        $description = is_string($data['description'] ?? null) ? mb_substr(trim($data['description']), 0, 50000) : null;
        $applyUrl = is_string($data['apply_url'] ?? null) && $data['apply_url'] !== '' ? $data['apply_url'] : $link->url;
        $existing = Opportunity::query()->where('normalized_application_url', ApplicationUrl::normalize($link->url))->first();

        try {
            $opportunity = $save->handle([
                'title' => trim($data['title']),
                'company' => trim($data['company']),
                'location' => is_string($data['location'] ?? null) && trim($data['location']) !== '' ? trim($data['location']) : $link->location,
                'requirements' => $description,
                'application_url' => $link->url,
                'source' => __('jobs.mail_source'),
                'extra_fields' => array_filter([
                    'Job alert' => $link->alert,
                    'LinkedIn notes' => $link->notes === null || $link->notes === [] ? null : implode('; ', $link->notes),
                    'Apply at' => $applyUrl === $link->url ? null : $applyUrl,
                    'Scanned' => now()->toDateString(),
                ]),
            ], $existing);
        } catch (ValidationException $exception) {
            return $this->markFailed($link, (string) (collect($exception->errors())->flatten()->first() ?? 'the page data was rejected'));
        }

        $link->update([
            'status' => LinkedInLink::IMPORTED,
            'opportunity_id' => $opportunity->id,
            'description' => $description,
            'apply_url' => $applyUrl === $link->url ? null : $applyUrl,
            'scanned_at' => now(),
            'scan_error' => null,
        ]);
        $this->line((string) json_encode(['ok' => true, 'opportunity' => $opportunity->id]));

        return self::SUCCESS;
    }

    private function markFailed(LinkedInLink $link, string $reason): int
    {
        $link->update(['status' => LinkedInLink::FAILED, 'scan_error' => mb_substr($reason, 0, 255), 'scanned_at' => now()]);
        $this->line((string) json_encode(['ok' => false, 'error' => mb_substr($reason, 0, 255)]));

        return self::SUCCESS;
    }
}
