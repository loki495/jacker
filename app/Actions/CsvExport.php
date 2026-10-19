<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\CsvMapping;
use BackedEnum;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExport
{
    public function handle(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $keys = [];
            foreach (Opportunity::query()->cursor() as $opportunity) {
                foreach (array_keys($opportunity->extra_fields ?? []) as $key) {
                    $keys[(string) $key] = true;
                }
            }
            $extras = array_keys($keys);
            sort($extras, SORT_STRING);
            $folded = array_count_values(array_map(fn (string $key): string => mb_strtolower(trim('extra:'.$key)), $extras));
            $headers = array_map(function (string $key) use ($folded): string {
                $header = 'extra:'.$key;

                return $key === '' || trim($header) !== $header || $folded[mb_strtolower(trim($header))] > 1
                    ? 'extra-hex:'.bin2hex($key) : $header;
            }, $extras);
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }
            fputcsv($stream, [...CsvMapping::FIELDS, ...$headers], ',', '"', '');
            foreach (Opportunity::query()->orderBy('id')->cursor() as $opportunity) {
                $values = [];
                foreach (CsvMapping::FIELDS as $field) {
                    $value = $opportunity->getAttribute($field);
                    if ($value instanceof BackedEnum) {
                        $value = $value->value;
                    } elseif ($value instanceof DateTimeInterface) {
                        $value = $value->format($field === 'archived_at' ? DATE_ATOM : 'Y-m-d');
                    } elseif (is_bool($value)) {
                        $value = $value ? 'yes' : 'no';
                    }
                    $values[] = $this->safe((string) ($value ?? ''));
                }
                foreach ($extras as $key) {
                    $value = ($opportunity->extra_fields ?? [])[$key] ?? '';
                    $values[] = $this->safe(is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR));
                }
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, 'opportunities-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function safe(string $value): string
    {
        return preg_match('/^[\p{Z}\p{C}\s]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
