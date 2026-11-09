<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmploymentType;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use App\Models\Opportunity;

/** The fields the listing API accepts; /api/info is generated from this so the two cannot drift. */
final class ListingSchema
{
    /** Filled together, and only while the listing has no pay at all. */
    public const array SALARY = ['salary_min', 'salary_max', 'currency', 'pay_period'];

    private const array TEXT = ['location', 'remote_restrictions', 'responsibilities', 'requirements', 'benefits', 'hours', 'contact', 'notes', 'salary_notes'];

    /** @return list<array{name: string, type: string, required: bool, max: int|null, values: list<string>|null, description: string}> */
    public static function fields(): array
    {
        $field = fn (string $name, string $type, bool $required = false, ?int $max = null, ?array $values = null): array => [
            'name' => $name, 'type' => $type, 'required' => $required, 'max' => $max, 'values' => $values, 'description' => __('api.fields.'.$name),
        ];
        $enum = fn (string $enum): array => array_column($enum::cases(), 'value');

        return [
            $field('company', 'string', true, 255),
            $field('application_url', 'url', true, 2048),
            $field('title', 'string', false, 255),
            $field('source', 'string', false, 255),
            $field('company_url', 'url', false, 2048),
            ...array_map(fn (string $name): array => $field($name, 'text', false, 50000), self::TEXT),
            $field('salary_min', 'number'),
            $field('salary_max', 'number'),
            $field('currency', 'string', false, 3),
            $field('pay_period', 'enum', false, null, $enum(PayPeriod::class)),
            $field('employment_type', 'enum', false, null, $enum(EmploymentType::class)),
            $field('work_arrangement', 'enum', false, null, $enum(WorkArrangement::class)),
            $field('deadline', 'date'),
            $field('extra_fields', 'object', false, 100),
        ];
    }

    /** Fields the finder can supply for a known listing; the rest identify it. */
    public const array IDENTITY = ['company', 'application_url'];

    public static function isEmpty(mixed $value): bool
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;
        $value = is_string($value) ? trim($value) : $value;

        return in_array($value, [null, '', 'unspecified', []], true);
    }

    /**
     * Fields a fill-only update could still add to the listing (pay counts once, as salary_min and salary_max).
     *
     * @return list<string>
     */
    public static function missing(Opportunity $listing): array
    {
        $missing = [];
        foreach (self::names() as $name) {
            if (in_array($name, [...self::IDENTITY, 'extra_fields', 'currency', 'pay_period', 'salary_min', 'salary_max'], true)) {
                continue;
            }
            if (self::isEmpty($listing->{$name})) {
                $missing[] = $name;
            }
        }
        if ($listing->salary_min === null && $listing->salary_max === null) {
            $missing[] = 'salary_min';
            $missing[] = 'salary_max';
        }

        return $missing;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::fields(), 'name');
    }

    public static function version(): string
    {
        return substr(sha1((string) json_encode(self::fields())), 0, 12);
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        return [
            'api_version' => 'v1',
            'schema_version' => self::version(),
            'compatibility' => __('api.compatibility'),
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/v1/info', 'purpose' => __('api.purpose_info')],
                ['method' => 'GET', 'path' => '/api/v1/profile', 'purpose' => __('api.purpose_profile')],
                ['method' => 'GET', 'path' => '/api/v1/listings', 'purpose' => __('api.purpose_known')],
                ['method' => 'POST', 'path' => '/api/v1/listings', 'purpose' => __('api.purpose_upsert')],
                ['method' => 'POST', 'path' => '/api/v1/mcp', 'purpose' => __('api.purpose_mcp')],
            ],
            'limits' => ['max_listings_per_request' => (int) config('api.max_batch'), 'max_known_per_page' => (int) config('api.max_page'), 'requests_per_minute' => (int) config('api.rate_limit')],
            'duplicate_rule' => __('api.duplicate_rule'),
            'extra_fields' => __('api.extra_fields_note'),
            'ignored' => __('api.ignored_note'),
            'fields' => self::fields(),
        ];
    }
}
