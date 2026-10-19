<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class CsvMapping
{
    public const array FIELDS = [
        'company', 'title', 'company_url', 'application_url', 'source', 'salary_min', 'salary_max',
        'currency', 'pay_period', 'salary_notes', 'benefits', 'hours', 'location', 'remote_restrictions',
        'responsibilities', 'requirements', 'contact', 'notes', 'employment_type', 'work_arrangement',
        'status', 'has_applied', 'applied_at', 'deadline', 'follow_up_at', 'skill_match', 'desirability',
        'skill_match_notes', 'desirability_notes', 'archived_at',
    ];

    /**
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public function suggest(array $headers): array
    {
        $aliases = ['company name' => 'company', 'job title' => 'title', 'url' => 'application_url', 'application url' => 'application_url',
            'pay range' => 'salary_notes', 'pay' => 'salary_notes', 'type' => 'work_arrangement',
            'responsabilities' => 'responsibilities', 'applied yet' => 'has_applied', 'match' => 'skill_match'];
        $mapping = [];
        foreach ($headers as $index => $header) {
            $name = mb_strtolower(trim(str_replace(['_', '-'], ' ', $header)));
            $field = $aliases[$name] ?? str_replace(' ', '_', $name);
            $mapping[$index] = in_array($field, self::FIELDS, true) ? $field : '';
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, mixed>  $mapping
     */
    public function validate(array $headers, array $mapping): void
    {
        foreach ($mapping as $field) {
            if (! is_string($field)) {
                throw ValidationException::withMessages(['mapping' => __('csv.mapping_choice')]);
            }
        }
        if (array_keys($mapping) !== array_keys($headers)) {
            throw ValidationException::withMessages(['mapping' => __('csv.mapping_columns')]);
        }
        $selected = array_filter($mapping, fn (string $value): bool => $value !== '');
        if (! in_array('company', $selected, true) || count(array_unique($selected)) !== count($selected)
            || array_diff($selected, self::FIELDS) !== []) {
            throw ValidationException::withMessages(['mapping' => __('csv.mapping_unique')]);
        }
        $keys = [];
        foreach ($headers as $index => $header) {
            if ($mapping[$index] === '') {
                $key = $this->extraKey($header);
                if (($key === '' && $header !== 'extra-hex:') || in_array($key, $keys, true)) {
                    throw ValidationException::withMessages(['mapping' => __('csv.mapping_extras')]);
                }
                $keys[] = $key;
            }
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $row
     * @param  array<int, string>  $mapping
     * @return array<string, mixed>
     */
    public function data(array $headers, array $row, array $mapping): array
    {
        $data = ['extra_fields' => []];
        foreach ($headers as $index => $header) {
            $field = $mapping[$index];
            if ($field === '') {
                $key = $this->extraKey($header);
                $data['extra_fields'][$key] = $row[$index];

                continue;
            }
            $value = trim($row[$index]);
            if ($value === '' && in_array($field, ['currency', 'status', 'work_arrangement', 'employment_type', 'pay_period', 'has_applied'], true)) {
                continue;
            }
            if (in_array($field, ['status', 'work_arrangement', 'employment_type', 'pay_period'], true)) {
                $value = preg_replace('/[\s-]+/u', '_', mb_strtolower($value)) ?? $value;
                if ($field === 'pay_period') {
                    $value = match ($value) {
                        'hourly', 'per_hour' => 'hour',
                        'daily', 'per_day' => 'day',
                        'weekly', 'per_week' => 'week',
                        'monthly', 'per_month' => 'month',
                        'yearly', 'annually', 'annual', 'per_year' => 'year',
                        default => $value,
                    };
                }
            }
            if ($field === 'has_applied') {
                $value = match (mb_strtolower($value)) {
                    'yes', 'y', 'true', '1' => true,
                    'no', 'n', 'false', '0' => false,
                    default => $value,
                };
            }
            if (in_array($field, ['applied_at', 'deadline', 'follow_up_at'], true) && $value !== ''
                && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw ValidationException::withMessages([$field => __('csv.iso_date')]);
            }
            $data[$field] = $value === '' ? null : $value;
        }

        return $data;
    }

    private function extraKey(string $header): string
    {
        if (str_starts_with($header, 'extra-hex:')) {
            $hex = substr($header, 10);
            if (preg_match('/^(?:[0-9a-f]{2})*$/D', $hex) === 1) {
                $key = hex2bin($hex);
                if (! mb_check_encoding($key, 'UTF-8')) {
                    throw ValidationException::withMessages(['mapping' => __('csv.mapping_encoding')]);
                }

                return $key;
            }
        }

        return str_starts_with($header, 'extra:') ? substr($header, 6) : $header;
    }
}
