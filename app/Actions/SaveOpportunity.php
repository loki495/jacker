<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\OpportunityData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveOpportunity
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, ?Opportunity $opportunity = null): Opportunity
    {
        $data = OpportunityData::validate($data, $opportunity);
        $opportunity ??= new Opportunity;
        try {
            return DB::transaction(function () use ($data, $opportunity): Opportunity {
                $previous = $opportunity->exists ? $opportunity->status : null;
                $opportunity->fill($data)->save();
                if ($previous !== $opportunity->status) {
                    $opportunity->events()->create(['from_status' => $previous, 'to_status' => $opportunity->status]);
                }

                return $opportunity->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (isset($data['normalized_application_url']) && Opportunity::query()->where('normalized_application_url', $data['normalized_application_url'])->whereKeyNot($opportunity->id ?? 0)->exists()) {
                throw ValidationException::withMessages(['application_url' => __('jobs.duplicate_url')]);
            }
            throw $exception;
        }
    }
}
