<?php

declare(strict_types=1);

use App\Actions\CsvExport;
use App\Models\Opportunity;
use App\Support\CsvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows a configured non-default host in any letter case and still rejects others', function (): void {
    config()->set('access.allowed_hosts', ['jacker.example.test']);

    $this->get('http://JACKER.Example.TEST/')->assertOk();
    $this->get('http://localhost/')->assertStatus(400);
});

it('reads a comma file when comma and semicolon give the same column count', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('tie.csv', "a,b;c\n1,2;3\n");

    $parsed = app(CsvReader::class)->read(Storage::disk('local')->path('tie.csv'));

    expect($parsed['delimiter'])->toBe(',')
        ->and($parsed['headers'])->toBe(['a', 'b;c']);
});

it('picks the dialect with the most columns when delimiters differ', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('semi.csv', "a;b;c\n1;2;3\n");

    expect(app(CsvReader::class)->read(Storage::disk('local')->path('semi.csv'))['delimiter'])->toBe(';');
});

it('exports dates as Y-m-d, timestamps as ISO 8601 and booleans as yes or no', function (): void {
    Opportunity::factory()->create([
        'company' => 'Formats Inc',
        'has_applied' => true,
        'deadline' => '2026-03-04',
        'archived_at' => '2026-05-06 07:08:09',
    ]);
    Storage::fake('local');
    ob_start();
    app(CsvExport::class)->handle()->sendContent();
    Storage::disk('local')->put('export.csv', (string) ob_get_clean());
    $parsed = app(CsvReader::class)->read(Storage::disk('local')->path('export.csv'));
    $row = array_combine($parsed['headers'], $parsed['rows'][0]);

    expect($row['has_applied'])->toBe('yes')
        ->and($row['deadline'])->toBe('2026-03-04')
        ->and($row['archived_at'])->toMatch('/^2026-05-06T07:08:09[+-]\d\d:\d\d$/');
});

it('sorts the overview by company, newest, follow-up and deadline with unset dates last', function (): void {
    $b = Opportunity::factory()->create(['company' => 'Bravo', 'follow_up_at' => '2026-02-01', 'deadline' => null, 'created_at' => '2026-01-01']);
    $a = Opportunity::factory()->create(['company' => 'Alpha', 'follow_up_at' => null, 'deadline' => '2026-09-01', 'created_at' => '2026-03-01']);
    $c = Opportunity::factory()->create(['company' => 'Charlie', 'follow_up_at' => '2026-01-01', 'deadline' => '2026-08-01', 'created_at' => '2026-02-01']);

    $ids = fn (string $sort): array => Livewire::test('pages::home')->set('sort', $sort)->instance()->opportunities->pluck('id')->all();

    expect($ids('company'))->toBe([$a->id, $b->id, $c->id])
        ->and($ids('newest'))->toBe([$a->id, $c->id, $b->id])
        ->and($ids('follow_up'))->toBe([$c->id, $b->id, $a->id])
        ->and($ids('deadline'))->toBe([$c->id, $a->id, $b->id]);
});

it('treats percent, underscore and backslash in search as literal characters', function (): void {
    $percent = Opportunity::factory()->create(['company' => '100% Remote Co']);
    $underscore = Opportunity::factory()->create(['company' => 'snake_case Ltd']);
    $backslash = Opportunity::factory()->create(['company' => 'Back\\slash LLC']);
    $plain = Opportunity::factory()->create(['company' => 'Plain Corp', 'title' => 'Engineer']);

    $found = fn (string $term): array => Livewire::test('pages::home')->set('search', $term)->instance()->opportunities->pluck('id')->all();

    expect($found('%'))->toBe([$percent->id])
        ->and($found('_'))->toBe([$underscore->id])
        ->and($found('\\'))->toBe([$backslash->id])
        ->and($found('zzz%'))->toBe([])
        ->and($plain->id)->not->toBeIn($found('%'));
});

it('filters the overview by the applied yes and no choice', function (): void {
    $yes = Opportunity::factory()->create(['has_applied' => true]);
    $no = Opportunity::factory()->create(['has_applied' => false]);

    $ids = fn (string $applied): array => Livewire::test('pages::home')->set('status', 'all')->set('applied', $applied)->instance()->opportunities->pluck('id')->all();

    expect($ids('yes'))->toBe([$yes->id])
        ->and($ids('no'))->toBe([$no->id])
        ->and($ids('bogus'))->toHaveCount(2);
});
