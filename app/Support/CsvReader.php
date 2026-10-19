<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class CsvReader
{
    /** @return array{headers: list<string>, rows: list<list<string>>, delimiter: string} */
    public function read(string $path): array
    {
        $limit = (int) config('csv.max_bytes', 5 * 1024 * 1024);
        if (! is_file($path) || filesize($path) > $limit) {
            $this->fail(__('csv.missing_or_large'));
        }
        $content = file_get_contents($path);
        if ($content === false || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $this->fail(__('csv.encoding'));
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $best = null;
        foreach ([',', ';', "\t"] as $delimiter) {
            try {
                $records = $this->records($content, $delimiter);
                $width = count($records[0] ?? []);
                if ($best === null || $width > count($best['headers'])) {
                    $best = ['headers' => array_shift($records) ?? [], 'rows' => $records, 'delimiter' => $delimiter];
                }
            } catch (ValidationException) {
                // A delimiter inside an unquoted value is valid for another dialect.
            }
        }
        if ($best === null || $best['headers'] === [] || $best['rows'] === []) {
            $this->fail(__('csv.records_required'));
        }
        $best['headers'] = array_map(trim(...), $best['headers']);
        $names = array_map(mb_strtolower(...), $best['headers']);
        if (in_array('', $names, true) || count(array_unique($names)) !== count($names)) {
            $this->fail(__('csv.headers_unique'));
        }
        foreach ($best['rows'] as $index => $row) {
            if (count($row) !== count($best['headers'])) {
                $this->fail(__('csv.width', ['record' => $index + 2]));
            }
        }

        return $best;
    }

    /** @return list<list<string>> */
    private function records(string $content, string $delimiter): array
    {
        $rows = [];
        $row = [];
        $cell = '';
        $quoted = false;
        $closed = false;
        $started = false;
        $length = strlen($content);
        $maxRows = (int) config('csv.max_rows', 1000);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($quoted) {
                if ($char === '"') {
                    if (($content[$i + 1] ?? '') === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                        $closed = true;
                    }
                } else {
                    $cell .= $char;
                }

                continue;
            }
            if (in_array($char, [$delimiter, "\n", "\r"], true)) {
                $row[] = $cell;
                $cell = '';
                $closed = $started = false;
                if ($char !== $delimiter) {
                    if ($char === "\r" && ($content[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    $rows[] = $row;
                    $row = [];
                    if (count($rows) > $maxRows + 1) {
                        $this->fail(__('csv.row_limit', ['rows' => $maxRows]));
                    }
                }
            } elseif ($char === '"' && ! $started && ! $closed) {
                $quoted = $started = true;
            } elseif ($char === '"' || $closed) {
                $this->fail(__('csv.quoting'));
            } else {
                $cell .= $char;
                $started = true;
            }
        }
        if ($quoted) {
            $this->fail(__('csv.unterminated'));
        }
        if ($row !== [] || $started || $closed) {
            $row[] = $cell;
            $rows[] = $row;
        }
        if (count($rows) > $maxRows + 1) {
            $this->fail(__('csv.row_limit', ['rows' => $maxRows]));
        }

        return $rows;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['upload' => $message]);
    }
}
