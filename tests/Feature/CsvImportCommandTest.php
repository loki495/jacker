<?php

declare(strict_types=1);

use App\Jobs\ProcessLinkCheck;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function importCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $body);

    return $path;
}

it('imports a CSV and skips exact URL duplicates on a second run', function (): void {
    $path = importCsv("company,title,url,location\nAcme,Senior Laravel Developer,https://example.com/a,Remote\nBeta,PHP Engineer,https://example.com/b,Los Angeles\n");

    $this->artisan('csv:import', ['path' => $path])->assertSuccessful()->expectsOutputToContain('Imported 2, skipped 0.');
    $this->artisan('csv:import', ['path' => $path])->assertSuccessful()->expectsOutputToContain('Imported 0, skipped 2.');

    expect(Opportunity::query()->count())->toBe(2);
    unlink($path);
});

it('skips invalid rows instead of blocking the import', function (): void {
    $path = importCsv("company,title,url,deadline\nAcme,Dev,https://example.com/a,not a date\nBeta,Dev,https://example.com/b,2026-12-01\n");

    $this->artisan('csv:import', ['path' => $path])->assertSuccessful()->expectsOutputToContain('Imported 1, skipped 1.');

    expect(Opportunity::query()->count())->toBe(1);
    unlink($path);
});

it('fails with a message when the file is missing', function (): void {
    $this->artisan('csv:import', ['path' => '/nonexistent.csv'])->expectsOutputToContain(__('csv.missing_or_large'))->assertFailed();
    expect(Opportunity::query()->count())->toBe(0);
});

it('skips a possible duplicate (same company, title and location, different link) and imports the rest', function (): void {
    Opportunity::factory()->create(['company' => 'Acme', 'title' => 'Senior Laravel Developer', 'location' => 'Remote', 'application_url' => 'https://example.com/old']);
    $path = importCsv("company,title,url,location\nacme,senior laravel developer,https://example.com/new,remote\nBeta,PHP Engineer,https://example.com/b,Los Angeles\n");

    $this->artisan('csv:import', ['path' => $path])->assertSuccessful()->expectsOutputToContain('Imported 1, skipped 1.');

    expect(Opportunity::query()->pluck('application_url')->all())->toContain('https://example.com/b')->not->toContain('https://example.com/new');
    unlink($path);
});

it('names the skipped record and its errors for an invalid row', function (): void {
    $path = importCsv("company,title,url,deadline\nAcme,Dev,https://example.com/a,not a date\n");

    $this->artisan('csv:import', ['path' => $path])->expectsOutputToContain('Skipping record 2:')->assertSuccessful();
    unlink($path);
});

it('queues link checks for imported jobs that have no requirements or responsibilities', function (): void {
    Queue::fake();
    $path = importCsv("company,title,url\nAcme,Senior Laravel Developer,https://example.com/a\n");

    $this->artisan('csv:import', ['path' => $path])->assertSuccessful()->expectsOutputToContain('Queued 1 link checks');

    Queue::assertPushed(ProcessLinkCheck::class, 1);
    unlink($path);
});
