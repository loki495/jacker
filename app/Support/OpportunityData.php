<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use App\Models\Opportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class OpportunityData
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function validate(array $data, ?Opportunity $opportunity = null): array
    {
        $defaults = ['currency' => 'USD', 'status' => 'saved', 'employment_type' => 'unspecified', 'work_arrangement' => 'unspecified', 'pay_period' => 'unspecified', 'has_applied' => false];
        $existing = $opportunity?->attributesToArray() ?? [];
        foreach (['applied_at', 'deadline', 'follow_up_at'] as $field) {
            if ($opportunity instanceof Opportunity) {
                $existing[$field] = $opportunity->{$field}?->format('Y-m-d');
            }
        }
        $data = array_replace($defaults, $existing, $data);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        $data['currency'] = is_string($data['currency']) ? strtoupper($data['currency']) : $data['currency'];
        $data['normalized_application_url'] = is_string($data['application_url'] ?? null) ? ApplicationUrl::normalize($data['application_url']) : null;
        $rules = [
            'company' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'company_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'application_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'normalized_application_url' => ['nullable', Rule::unique('opportunities')->ignore($opportunity?->id)],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'salary_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'pay_period' => ['required', Rule::enum(PayPeriod::class)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'work_arrangement' => ['required', Rule::enum(WorkArrangement::class)],
            'status' => ['required', Rule::enum(OpportunityStatus::class)],
            'has_applied' => ['required', 'boolean'],
            'applied_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'follow_up_at' => ['nullable', 'date_format:Y-m-d'],
            'skill_match' => ['nullable', 'integer', 'between:1,5'],
            'desirability' => ['nullable', 'integer', 'between:1,5'],
            'archived_at' => ['nullable', 'date'],
            'extra_fields' => ['nullable', 'array', 'max:100'],
            'extra_fields.*' => ['nullable', 'string', 'max:20000'],
        ];
        foreach (['salary_notes', 'benefits', 'hours', 'location', 'remote_restrictions', 'responsibilities', 'requirements', 'contact', 'notes', 'skill_match_notes', 'desirability_notes'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:50000'];
        }
        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data): void {
            if ($validator->errors()->has('normalized_application_url')) {
                $validator->errors()->add('application_url', __('jobs.duplicate_url'));
            }
            if (is_numeric($data['salary_min'] ?? null) && is_numeric($data['salary_max'] ?? null) && $data['salary_min'] > $data['salary_max']) {
                $validator->errors()->add('salary_max', __('jobs.salary_order'));
            }
        });
        $validated = $validator->validate();
        if (! empty($validated['archived_at'])) {
            $validated['archived_at'] = Carbon::parse($validated['archived_at'])
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        }
        $status = OpportunityStatus::from($validated['status']);
        $validated['has_applied'] = (bool) $validated['has_applied'] || $status->impliesApplied() || ! empty($validated['applied_at']);

        return $validated;
    }
}
