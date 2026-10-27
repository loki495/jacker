<?php

declare(strict_types=1);

use App\Actions\CsvExport;
use App\Actions\CsvImport;
use App\Actions\CsvPreview;
use App\Actions\SaveOpportunity;
use App\Http\Controllers\CsvExportController;
use App\Models\Opportunity;
use App\Support\CsvMapping;
use App\Support\CsvReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function csvTestFile(string $content): string
{
    $name = 'test-'.Str::uuid().'.csv';
    Storage::disk('local')->put($name, $content);

    return Storage::disk('local')->path($name);
}

function csvTestMapping(string $path): array
{
    return app(CsvMapping::class)->suggest(app(CsvReader::class)->read($path)['headers']);
}

it('reads BOM semicolon and tab dialects with quoted multiline cells', function (string $delimiter): void {
    $path = csvTestFile("\xEF\xBB\xBFcompany{$delimiter}notes\r\nAcme{$delimiter}\"Line one\nLine \"\"two\"\"\"\r\n");
    $csv = app(CsvReader::class)->read($path);
    expect($csv['headers'])->toBe(['company', 'notes'])
        ->and($csv['delimiter'])->toBe($delimiter)
        ->and($csv['rows'])->toBe([['Acme', "Line one\nLine \"two\""]]);
})->with([',', ';', "\t"]);

it('rejects malformed CSV with a handled upload error', function (string $content): void {
    try {
        app(CsvReader::class)->read(csvTestFile($content));
        $this->fail('Expected CSV upload validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('upload');
    }
})->with([
    'empty' => '', 'header only' => "company\n", 'duplicate headers' => "company,Company\nAcme,Other\n",
    'blank header' => "company,\nAcme,Other\n", 'width' => "company,title\nAcme,Engineer,Unexpected\n",
    'unclosed quote' => "company,notes\nAcme,\"unfinished\n", 'stray quote' => "company,notes\nAcme,bad\"quote\n",
    'invalid utf8' => "company\n\xC3\x28\n", 'null' => "company\nAcme\0\n",
]);

it('enforces configured byte and row limits', function (): void {
    config(['csv.max_bytes' => 5]);
    expect(fn () => app(CsvReader::class)->read(csvTestFile("company\nAcme\n")))->toThrow(ValidationException::class);
    config(['csv.max_bytes' => 5242880, 'csv.max_rows' => 1]);
    expect(fn () => app(CsvReader::class)->read(csvTestFile("company\nAcme\nOther\n")))->toThrow(ValidationException::class);
});

it('maps aliases and preserves pay text and every unknown field value', function (): void {
    $path = csvTestFile("company name,application url,pay range,applied yet,match,Custom field,Empty\nAcme,https://example.test/apply,\"$50–$70 per hour\",yes,4,\"  original text  \",\n");
    $result = app(CsvImport::class)->handle($path, csvTestMapping($path));
    $opportunity = Opportunity::query()->sole();
    expect($result)->toBe(['imported' => 1, 'skipped' => 0])
        ->and($opportunity->has_applied)->toBeTrue()->and($opportunity->applied_at)->toBeNull()
        ->and($opportunity->salary_notes)->toBe('$50–$70 per hour')
        ->and($opportunity->salary_min)->toBeNull()->and($opportunity->skill_match)->toBe(4)
        ->and($opportunity->extra_fields)->toBe(['Custom field' => '  original text  ', 'Empty' => '']);
});

it('requires explicit exclusion of invalid dates and invalid rows before any writes', function (): void {
    $path = csvTestFile("company,applied_at\nValid,\nInvalid,03/04/2025\n");
    $mapping = csvTestMapping($path);
    $preview = app(CsvPreview::class)->handle($path, $mapping);
    expect($preview[1]['errors'])->toContain('Use an unambiguous ISO date: YYYY-MM-DD.');
    try {
        app(CsvImport::class)->handle($path, $mapping);
        $this->fail('Expected explicit invalid exclusion validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('import')->and(Opportunity::query()->count())->toBe(0);
    }
    expect(app(CsvImport::class)->handle($path, $mapping, [3]))->toBe(['imported' => 1, 'skipped' => 1]);
});

it('imports representative spreadsheet headings and human enum values', function (string $arrangement, string $period, string $expectedArrangement, string $expectedPeriod): void {
    $path = csvTestFile("Company name,Job title,URL,Pay range,Benefits,Hours,Type,Location,Responsabilities,Requirements,Applied yet,Match,Desirability,Employment type,Status,Pay period\nAcme,Engineer,https://example.test/apply,Market rate,Health,Flexible,{$arrangement},West coast,Build things,PHP,Yes,4,5,Full time,Applied,{$period}\n");
    expect(app(CsvImport::class)->handle($path, csvTestMapping($path)))->toBe(['imported' => 1, 'skipped' => 0]);
    $opportunity = Opportunity::query()->sole();
    expect($opportunity->title)->toBe('Engineer')
        ->and($opportunity->work_arrangement->value)->toBe($expectedArrangement)
        ->and($opportunity->employment_type->value)->toBe('full_time')
        ->and($opportunity->status->value)->toBe('applied')
        ->and($opportunity->pay_period->value)->toBe($expectedPeriod)
        ->and($opportunity->has_applied)->toBeTrue()->and($opportunity->applied_at)->toBeNull()
        ->and($opportunity->responsibilities)->toBe('Build things')
        ->and($opportunity->salary_notes)->toBe('Market rate')->and($opportunity->salary_min)->toBeNull();
})->with([
    ['Remote', 'Yearly', 'remote', 'year'], ['on site', 'Hourly', 'on_site', 'hour'],
    ['on-site', 'per month', 'on_site', 'month'], ['HYBRID', 'Weekly', 'hybrid', 'week'],
]);

it('uses core defaults for blank defaulted cells and rejects unsupported enum labels', function (): void {
    $path = csvTestFile("company,currency,status,work_arrangement,employment_type,pay_period,has_applied\nAcme,,,,,,\n");
    expect(app(CsvImport::class)->handle($path, csvTestMapping($path)))->toBe(['imported' => 1, 'skipped' => 0]);
    $opportunity = Opportunity::query()->sole();
    expect($opportunity->currency)->toBe('USD')->and($opportunity->status->value)->toBe('saved')
        ->and($opportunity->work_arrangement->value)->toBe('unspecified')
        ->and($opportunity->employment_type->value)->toBe('unspecified')
        ->and($opportunity->pay_period->value)->toBe('unspecified')->and($opportunity->has_applied)->toBeFalse();
    $path = csvTestFile("company,type\nOther,Anywhereish\n");
    expect(app(CsvPreview::class)->handle($path, csvTestMapping($path))[0]['errors'])->not->toBeEmpty();
    try {
        app(CsvImport::class)->handle($path, csvTestMapping($path));
        $this->fail('Expected unsupported work arrangement validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('import')->and(Opportunity::query()->count())->toBe(1);
    }
});

it('rejects duplicate invalid missing and tampered field mappings', function (array $mapping): void {
    $path = csvTestFile("company,title\nAcme,Engineer\n");
    try {
        app(CsvImport::class)->handle($path, $mapping);
        $this->fail('Expected mapping validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('mapping')->and(Opportunity::query()->count())->toBe(0);
    }
})->with([
    'duplicate' => [['company', 'company']], 'missing company' => [['title', '']],
    'unsupported' => [['company', 'id']], 'missing column' => [['company']], 'nested tampering' => [['company', ['bad']]],
]);

it('always skips normalized URL duplicates and defaults candidates to skipped', function (): void {
    app(SaveOpportunity::class)->handle(['company' => 'Acme', 'title' => 'Engineer', 'location' => 'Remote', 'application_url' => 'https://example.test/apply']);
    $path = csvTestFile("company,title,location,application_url\nAcme,Engineer,Remote,HTTPS://EXAMPLE.TEST/apply/#fragment\nAcme,Engineer,Remote,\nNew,Role,Here,https://example.test/new\nSecond,Role,Here,https://example.test/new/\n");
    $mapping = csvTestMapping($path);
    expect(array_column(app(CsvPreview::class)->handle($path, $mapping), 'duplicate'))->toBe(['url', 'candidate', null, 'url']);
    expect(app(CsvImport::class)->handle($path, $mapping, [], [2, 3, 5]))->toBe(['imported' => 2, 'skipped' => 2])
        ->and(Opportunity::query()->count())->toBe(3);
});

it('rechecks duplicates and canonical file validation at commit', function (): void {
    $path = csvTestFile("company,application_url\nAcme,https://example.test/new\n");
    $mapping = csvTestMapping($path);
    expect(app(CsvPreview::class)->handle($path, $mapping)[0]['duplicate'])->toBeNull();
    app(SaveOpportunity::class)->handle(['company' => 'Acme', 'application_url' => 'https://example.test/new/']);
    expect(app(CsvImport::class)->handle($path, $mapping))->toBe(['imported' => 0, 'skipped' => 1]);
    file_put_contents($path, "company,application_url\n,https://example.test/invalid\n");
    expect(fn () => app(CsvImport::class)->handle($path, $mapping))->toThrow(ValidationException::class)
        ->and(Opportunity::query()->count())->toBe(1);
});

it('rolls back every opportunity and history event on a database failure', function (): void {
    DB::unprepared("CREATE TRIGGER fail_csv_insert BEFORE INSERT ON opportunities WHEN NEW.company = 'Boom' BEGIN SELECT RAISE(ABORT, 'csv failure'); END");
    $path = csvTestFile("company\nGood\nBoom\n");
    expect(fn () => app(CsvImport::class)->handle($path, csvTestMapping($path)))->toThrow(QueryException::class, 'csv failure')
        ->and(Opportunity::query()->count())->toBe(0)
        ->and(DB::table('opportunity_events')->count())->toBe(0);
});

it('exports union extras and defuses formulas including leading whitespace and controls', function (): void {
    app(SaveOpportunity::class)->handle(['company' => " \t=SUM(1,2)", 'notes' => "\n@evil", 'extra_fields' => ['company' => '+cmd', 'Z' => "\t-42"]]);
    app(SaveOpportunity::class)->handle(['company' => 'Other', 'extra_fields' => ['A' => 'preserved']]);
    $response = app(CsvExport::class)->handle();
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    $parsed = app(CsvReader::class)->read(csvTestFile($csv));
    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and(array_slice($parsed['headers'], -3))->toBe(['extra:A', 'extra:Z', 'extra:company'])
        ->and($parsed['rows'][0][0])->toStartWith("'=")
        ->and($parsed['rows'][0][array_search('notes', $parsed['headers'], true)])->toBe("'@evil")
        ->and(array_slice($parsed['rows'][0], -3))->toBe(['', "'\t-42", "'+cmd"]);
});

it('safely reimports identical exports with URL and no-URL duplicates', function (): void {
    app(SaveOpportunity::class)->handle(['company' => 'Acme', 'application_url' => 'https://example.test/a', 'extra_fields' => ['company' => 'raw']]);
    app(SaveOpportunity::class)->handle(['company' => 'No URL']);
    ob_start();
    app(CsvExport::class)->handle()->sendContent();
    $path = csvTestFile((string) ob_get_clean());
    expect(app(CsvImport::class)->handle($path, csvTestMapping($path)))->toBe(['imported' => 0, 'skipped' => 2])
        ->and(Opportunity::query()->count())->toBe(2);
});

it('reimports archive state and extra keys that collide with core field names', function (): void {
    app(SaveOpportunity::class)->handle(['company' => 'Archived export', 'archived_at' => '2025-01-02T03:04:05+00:00',
        'extra_fields' => ['company' => 'Original custom company', 'Untouched' => '  spaces  ']]);
    ob_start();
    app(CsvExport::class)->handle()->sendContent();
    $path = csvTestFile((string) ob_get_clean());
    Opportunity::query()->delete();
    expect(app(CsvImport::class)->handle($path, csvTestMapping($path)))->toBe(['imported' => 1, 'skipped' => 0]);
    $opportunity = Opportunity::query()->sole();
    expect($opportunity->archived_at->utc()->toIso8601String())->toBe('2025-01-02T03:04:05+00:00')
        ->and($opportunity->extra_fields)->toBe(['Untouched' => '  spaces  ', 'company' => 'Original custom company']);
});

it('roundtrips extra key unions with case collisions whitespace numeric and empty keys', function (): void {
    app(SaveOpportunity::class)->handle(['company' => 'Upper', 'extra_fields' => ['A' => 'upper', 'Trailing ' => 'kept', 42 => 'numeric']]);
    app(SaveOpportunity::class)->handle(['company' => 'Lower', 'extra_fields' => ['a' => 'lower', '' => 'empty-key']]);
    ob_start();
    app(CsvExport::class)->handle()->sendContent();
    $path = csvTestFile((string) ob_get_clean());
    $headers = app(CsvReader::class)->read($path)['headers'];
    expect($headers)->toContain('extra-hex:41', 'extra-hex:61', 'extra-hex:'.bin2hex('Trailing '), 'extra:42', 'extra-hex:');
    Opportunity::query()->delete();
    expect(app(CsvImport::class)->handle($path, csvTestMapping($path)))->toBe(['imported' => 2, 'skipped' => 0]);
    $upper = Opportunity::query()->where('company', 'Upper')->sole()->extra_fields;
    $lower = Opportunity::query()->where('company', 'Lower')->sole()->extra_fields;
    expect($upper['A'])->toBe('upper')->and($upper['Trailing '])->toBe('kept')->and($upper[42])->toBe('numeric')
        ->and($lower['a'])->toBe('lower')->and($lower[''])->toBe('empty-key');
});

it('rejects encoded non-UTF8 extra keys as a mapping validation error', function (): void {
    $path = csvTestFile("company,extra-hex:ff\nAcme,Value\n");
    try {
        app(CsvImport::class)->handle($path, csvTestMapping($path));
        $this->fail('Expected encoded key validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('mapping')->and(Opportunity::query()->count())->toBe(0);
    }
});

it('handles invalid Livewire uploads and mapping without a crash', function (): void {
    Livewire::test('pages::import')->call('loadUpload')->assertHasErrors(['upload' => 'required']);
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('invalid.csv', "company,company\nA,B\n"))
        ->call('loadUpload')->assertHasErrors('upload');
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('valid.csv', "company,title\nAcme,Role\n"))
        ->call('loadUpload')->assertHasNoErrors()
        ->set('mapping', ['title', 'title'])->call('preview')->assertHasErrors('mapping');
});

it('keeps preview bounded and requires reviewed invalid exclusion in Livewire', function (): void {
    $content = "company,applied_at\nGood,\nBad,01/02/2024\n";
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('valid.csv', $content))
        ->call('loadUpload')->call('preview')->assertHasNoErrors()->assertSee('YYYY-MM-DD')
        ->call('importReviewed')->assertHasErrors('import')
        ->set('excluded', [3])->call('importReviewed')->assertHasNoErrors()->assertSee('1 imported; 1 skipped.');
    expect(Opportunity::query()->count())->toBe(1);
    $rows = implode("\n", array_map(fn (int $i): string => 'Company '.$i, range(1, 41)));
    $component = Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('many.csv', "company\n".$rows."\n"))
        ->call('loadUpload')->call('preview')->assertSee('Company 20')->assertDontSee('Company 21');
    expect($component->get('previewData')['rows'])->toHaveCount(20);
    $component->set('page', 2)->assertSee('Company 21')->assertDontSee('Company 41');
});

it('rejects commit without a canonical reviewed upload', function (): void {
    Livewire::test('pages::import')->call('preview')->assertHasErrors('upload');
    Livewire::test('pages::import')->call('importReviewed')->assertHasErrors('import');
});

it('returns a streamed HTTP attachment including an empty export', function (): void {
    Route::get('/csv-controller-test', CsvExportController::class);
    $response = $this->get('/csv-controller-test')->assertOk()->assertDownload('opportunities-'.now()->format('Y-m-d').'.csv');
    expect($response->streamedContent())->toStartWith('company,title,company_url,application_url,');
    app(SaveOpportunity::class)->handle(['company' => 'HTTP exported']);
    expect($this->get('/csv-controller-test')->assertOk()->streamedContent())->toContain('HTTP exported');
});

it('handles an expired canonical upload during commit and render', function (): void {
    $component = Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('valid.csv', "company\nAcme\n"))
        ->call('loadUpload')->call('preview')->assertHasNoErrors();
    Storage::disk('local')->deleteDirectory('csv-imports');
    $component->call('importReviewed')->assertHasErrors('upload')->assertSee('This upload has expired.');
    expect(Opportunity::query()->count())->toBe(0);
});

it('normalizes loose work arrangement and employment type values', function () {
    $mapping = app(CsvMapping::class);
    $data = fn (string $field, string $value) => $mapping->data([$field], [$value], [$field])[$field];

    expect($data('work_arrangement', 'onsite'))->toBe('on_site')
        ->and($data('work_arrangement', 'On-site'))->toBe('on_site')
        ->and($data('work_arrangement', 'remote_first'))->toBe('remote')
        ->and($data('work_arrangement', 'hybrid_after_training'))->toBe('hybrid')
        ->and($data('employment_type', 'full_time_or_unclear'))->toBe('full_time')
        ->and($data('employment_type', 'unclear'))->toBe('unspecified');
});

it('offers the export on the import page and not in the sidebar', function (): void {
    Livewire::test('pages::import')->assertSee('Import / Export')->assertSeeHtml('href="/export"');
    $this->get('/')->assertDontSee('Export CSV')->assertSee('Import / Export');
});
