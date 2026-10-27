<?php

declare(strict_types=1);

use App\Support\CsvMapping;

it('suggests mapping aliases for headers', function (): void {
    expect((new CsvMapping)->suggest(['Company Name', 'Job_Title', 'URL', 'Pay-Range', 'Type', 'Nope']))
        ->toBe(['company', 'title', 'application_url', 'salary_notes', 'work_arrangement', '']);
});

it('normalizes work arrangement values', function (string $input, string $expected): void {
    $data = (new CsvMapping)->data(['w'], [$input], ['work_arrangement']);
    expect($data['work_arrangement'])->toBe($expected);
})->with([
    ['Remote (US)', 'remote'], ['remote_only', 'remote'], ['Telework', 'remote'], ['Hybrid - 2 days', 'hybrid'],
    ['Onsite', 'on_site'], ['On-site', 'on_site'], ['on site', 'on_site'], ['Anywhereish', 'anywhereish'],
]);

it('normalizes employment type values', function (string $input, string $expected): void {
    $data = (new CsvMapping)->data(['e'], [$input], ['employment_type']);
    expect($data['employment_type'])->toBe($expected);
})->with([
    ['Full time', 'full_time'], ['Full-time (permanent)', 'full_time'], ['Part time', 'part_time'], ['part_time_contract', 'part_time'],
    ['Unclear', 'unspecified'], ['Contract', 'contract'],
]);
