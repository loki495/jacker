<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Support\ApplicationUrl;
use App\Support\CsvMapping;
use App\Support\CsvReader;
use App\Support\OpportunityData;
use Illuminate\Validation\ValidationException;

final readonly class CsvPreview
{
    public function __construct(private CsvReader $reader, private CsvMapping $mapping) {}

    /**
     * @param  array<int, string>  $mapping
     * @return list<array{number: int, data: array<string, mixed>, errors: list<string>, duplicate: ?string}>
     */
    public function handle(string $path, array $mapping): array
    {
        $csv = $this->reader->read($path);
        $this->mapping->validate($csv['headers'], $mapping);
        $urls = [];
        $candidates = [];
        foreach (Opportunity::query()->get() as $opportunity) {
            if ($opportunity->normalized_application_url !== null) {
                $urls[$opportunity->normalized_application_url] = $opportunity;
            }
            $candidates[$this->identity($opportunity->toArray())] = true;
        }
        $preview = [];
        foreach ($csv['rows'] as $index => $row) {
            $data = [];
            $errors = [];
            $duplicate = null;
            try {
                $data = $this->mapping->data($csv['headers'], $row, $mapping);
                $url = ApplicationUrl::normalize($data['application_url'] ?? null);
                $existing = $url !== null ? ($urls[$url] ?? null) : null;
                $duplicate = $existing !== null ? 'url' : (isset($candidates[$this->identity($data)]) ? 'candidate' : null);
                $data = app(OpportunityData::class)->validate($data, $existing instanceof Opportunity ? $existing : null);
                if ($url !== null) {
                    $urls[$url] = $existing ?? true;
                }
                $candidates[$this->identity($data)] = true;
            } catch (ValidationException $exception) {
                $errors = array_values(array_merge(...array_values($exception->errors())));
            }
            $preview[] = ['number' => $index + 2, 'data' => $data, 'errors' => $errors, 'duplicate' => $duplicate];
        }

        return $preview;
    }

    /** @param array<string, mixed> $data */
    private function identity(array $data): string
    {
        return json_encode(array_map(fn (string $field): string => mb_strtolower(trim((string) ($data[$field] ?? ''))), ['company', 'title', 'location']), JSON_THROW_ON_ERROR);
    }
}
