<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CsvImport
{
    public function __construct(private CsvPreview $preview, private SaveOpportunity $save) {}

    /**
     * @param  array<int, string>  $mapping
     * @param  list<int|string>  $excluded
     * @param  list<int|string>  $includedCandidates
     * @return array{imported: int, skipped: int}
     */
    public function handle(string $path, array $mapping, array $excluded = [], array $includedCandidates = []): array
    {
        Validator::make(['excluded' => $excluded, 'includedCandidates' => $includedCandidates], [
            'excluded' => ['array', 'max:'.(int) config('csv.max_rows', 1000)], 'excluded.*' => ['integer', 'min:2'],
            'includedCandidates' => ['array', 'max:'.(int) config('csv.max_rows', 1000)], 'includedCandidates.*' => ['integer', 'min:2'],
        ])->validate();

        return DB::transaction(function () use ($path, $mapping, $excluded, $includedCandidates): array {
            $rows = $this->preview->handle($path, $mapping);
            foreach ($rows as $row) {
                if ($row['duplicate'] !== 'url' && $row['errors'] !== [] && ! in_array($row['number'], $excluded)) {
                    throw ValidationException::withMessages(['import' => __('csv.exclude_invalid', ['record' => $row['number']])]);
                }
            }
            $result = ['imported' => 0, 'skipped' => 0];
            foreach ($rows as $row) {
                if (in_array($row['number'], $excluded) || $row['duplicate'] === 'url'
                    || ($row['duplicate'] === 'candidate' && ! in_array($row['number'], $includedCandidates))) {
                    $result['skipped']++;

                    continue;
                }
                $this->save->handle($row['data']);
                $result['imported']++;
            }

            return $result;
        });
    }
}
