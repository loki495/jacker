<?php

declare(strict_types=1);

namespace App\Actions;

/** Runs a batch through UpsertListing; one bad item never stops the rest. */
final readonly class UpsertListings
{
    public function __construct(private UpsertListing $upsert) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{summary: array<string, int>, results: list<array<string, mixed>>}
     */
    public function handle(array $items): array
    {
        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0];
        foreach ($items as $index => $item) {
            $result = $this->upsert->handle($item);
            $summary[$result['result']]++;
            $results[] = ['index' => $index, ...$result];
        }

        return ['summary' => $summary, 'results' => $results];
    }
}
