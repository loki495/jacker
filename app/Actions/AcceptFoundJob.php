<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FoundJobStatus;
use App\Models\FoundJob;
use App\Models\Opportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcceptFoundJob
{
    public function __construct(private SaveOpportunity $save) {}

    /**
     * Turns a staged listing into an Opportunity. A duplicate of an existing application link dismisses the listing instead.
     *
     * @throws ValidationException
     */
    public function handle(FoundJob $found): Opportunity
    {
        try {
            return DB::transaction(function () use ($found): Opportunity {
                $opportunity = $this->save->handle(array_filter([
                    'company' => $found->company ?? __('scrapers.unknown_company'),
                    'title' => $found->title,
                    'application_url' => $found->url,
                    'source' => __('scrapers.source_'.$found->source),
                    'company_url' => $found->company_url,
                    'location' => $found->location,
                    'remote_restrictions' => $found->remote_restrictions,
                    'requirements' => $found->requirements,
                    'deadline' => $found->deadline?->format('Y-m-d'),
                    'notes' => $this->notes($found),
                    'work_arrangement' => $found->work_arrangement,
                    'employment_type' => $found->employment_type,
                    'salary_min' => $found->salary_min,
                    'salary_max' => $found->salary_max,
                    'currency' => $found->currency,
                    'pay_period' => $found->pay_period,
                    'salary_notes' => $found->salary_notes,
                    'responsibilities' => $found->description,
                ], fn (mixed $value): bool => $value !== null));
                $found->update(['status' => FoundJobStatus::Accepted, 'opportunity_id' => $opportunity->id]);

                return $opportunity;
            });
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['application_url'])) {
                $found->update(['status' => FoundJobStatus::Dismissed]);
            }
            throw $exception;
        }
    }

    private function notes(FoundJob $found): ?string
    {
        $lines = array_filter([$found->notes, $found->posted_at === null ? null : __('scrapers.posted_note', ['date' => $found->posted_at->format('M j, Y')])]);

        return $lines === [] ? null : implode("\n", $lines);
    }
}
