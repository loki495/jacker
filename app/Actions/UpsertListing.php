<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\ApplicationUrl;
use App\Support\ListingSchema;
use Illuminate\Validation\ValidationException;

final readonly class UpsertListing
{
    public function __construct(private SaveOpportunity $save) {}

    /**
     * Creates the listing, or fills only the still-empty fields of the one with the same application link.
     *
     * @param  array<string, mixed>  $item
     * @return array{result: string, id: int|null, filled: list<string>, ignored: list<string>, errors: array<string, list<string>>}
     */
    public function handle(array $item): array
    {
        $accepted = array_intersect_key($item, array_flip(ListingSchema::names()));
        $ignored = array_values(array_diff(array_keys($item), array_keys($accepted)));
        $url = is_string($accepted['application_url'] ?? null) ? ApplicationUrl::normalize($accepted['application_url']) : null;
        $existing = $url === null ? null : Opportunity::query()->where('normalized_application_url', $url)->first();

        try {
            if (! $existing instanceof Opportunity) {
                if ($url === null) {
                    throw ValidationException::withMessages(['application_url' => __('validation.required', ['attribute' => 'application url'])]);
                }
                $created = $this->save->handle($accepted);

                return $this->result('created', $created->id, [], $ignored);
            }
            $fill = $this->fillable($existing, $accepted);
            if ($fill === []) {
                return $this->result('unchanged', $existing->id, [], $ignored);
            }
            $this->save->handle($fill, $existing);

            return $this->result('updated', $existing->id, array_keys($fill), $ignored);
        } catch (ValidationException $exception) {
            return $this->result('invalid', $existing?->id, [], $ignored, $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function fillable(Opportunity $existing, array $incoming): array
    {
        $incoming = array_filter($incoming, fn (mixed $value): bool => ! ListingSchema::isEmpty($value));
        $fill = [];
        foreach ($incoming as $key => $value) {
            if (in_array($key, ['company', 'application_url', 'extra_fields', ...ListingSchema::SALARY], true)) {
                continue;
            }
            if (ListingSchema::isEmpty($existing->{$key})) {
                $fill[$key] = $value;
            }
        }
        if ($existing->salary_min === null && $existing->salary_max === null && (isset($incoming['salary_min']) || isset($incoming['salary_max']))) {
            $fill += array_intersect_key($incoming, array_flip(ListingSchema::SALARY));
        }
        if (is_array($incoming['extra_fields'] ?? null)) {
            $known = $existing->extra_fields ?? [];
            $new = array_diff_key($incoming['extra_fields'], $known);
            if ($new !== []) {
                $fill['extra_fields'] = $known + $new;
            }
        }

        return $fill;
    }

    /**
     * @param  list<string>  $filled
     * @param  list<string>  $ignored
     * @param  array<string, list<string>>  $errors
     * @return array{result: string, id: int|null, filled: list<string>, ignored: list<string>, errors: array<string, list<string>>}
     */
    private function result(string $result, ?int $id, array $filled, array $ignored, array $errors = []): array
    {
        return ['result' => $result, 'id' => $id, 'filled' => $filled, 'ignored' => $ignored, 'errors' => $errors];
    }
}
