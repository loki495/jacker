<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\ListingGaps;
use App\Support\ListingSchema;

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
            $query->where(ListingGaps::apply(...));
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
}
