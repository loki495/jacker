<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CsvImport;
use App\Actions\CsvPreview;
use App\Support\CsvMapping;
use App\Support\CsvReader;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CsvImportCommand extends Command
{
    #[\Override]
    protected $signature = 'csv:import {path : UTF-8 CSV file to import}';

    #[\Override]
    protected $description = 'Import a CSV without review: headers are auto-mapped, invalid rows and possible duplicates are skipped, exact URL duplicates never import twice.';

    public function handle(CsvReader $reader, CsvMapping $mapping, CsvPreview $preview, CsvImport $import): int
    {
        $path = (string) $this->argument('path');
        try {
            $map = $mapping->suggest($reader->read($path)['headers']);
            $invalid = [];
            foreach ($preview->handle($path, $map) as $row) {
                if ($row['errors'] !== [] && $row['duplicate'] !== 'url') {
                    $invalid[] = $row['number'];
                    $this->warn(__('csv.cli_invalid', ['record' => $row['number'], 'errors' => implode('; ', $row['errors'])]));
                }
            }
            $result = $import->handle($path, $map, $invalid);
        } catch (ValidationException $exception) {
            $this->error(implode(' ', array_merge(...array_values($exception->errors()))));

            return self::FAILURE;
        }
        $this->info(__('csv.cli_done', ['imported' => $result['imported'], 'skipped' => $result['skipped']]));

        return self::SUCCESS;
    }
}
