<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\ListingSchema;
use Illuminate\Database\Eloquent\Builder;

final class ListKnownListings
{
    /**
     * @return array{total: int, page: int, per_page: int, last_page: int, listings: list<array<string, mixed>>}
     */
    public function handle(int $page = 1, int $perPage = 100, bool $onlyMissing = false): array
    {
        $perPage = max(1, min($perPage, (int) config('api.max_page')));
        $query = Opportunity::query()->whereNotNull('normalized_application_url')->orderBy('id');
        if ($onlyMissing) {
            $query->where(fn (Builder $q): Builder => $this->anyMissing($q));
        }
        $paginator = $query->paginate($perPage, page: max(1, $page));

        return [
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $perPage,
            'last_page' => $paginator->lastPage(),
            'listings' => $paginator->getCollection()->map(fn (Opportunity $o): array => [
                'id' => $o->id,
                'application_url' => $o->application_url,
                'company' => $o->company,
                'title' => $o->title,
                'status' => $o->status->value,
                'missing' => ListingSchema::missing($o),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Builder<Opportunity>  $query
     * @return Builder<Opportunity>
     */
    private function anyMissing(Builder $query): Builder
    {
        $enums = ['employment_type', 'work_arrangement'];
        foreach (ListingSchema::names() as $name) {
            if (in_array($name, [...ListingSchema::IDENTITY, 'extra_fields', 'currency', 'pay_period', 'salary_min', 'salary_max'], true)) {
                continue;
            }
            match (true) {
                in_array($name, $enums, true) => $query->orWhere($name, 'unspecified'),
                $name === 'deadline' => $query->orWhereNull($name),
                default => $query->orWhereNull($name)->orWhere($name, ''),
            };
        }

        return $query->orWhere(fn (Builder $q): Builder => $q->whereNull('salary_min')->whereNull('salary_max'));
    }
}
