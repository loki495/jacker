<?php

declare(strict_types=1);

use App\Actions\ImportSavedPage;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function savedPosting(): string
{
    $posting = json_encode(['@type' => 'JobPosting', 'title' => 'Senior PHP Engineer', 'hiringOrganization' => ['name' => 'Acme'], 'jobLocation' => ['address' => ['addressLocality' => 'Austin', 'addressRegion' => 'TX']]]);

    return '<html><head><script type="application/ld+json">'.$posting.'</script></head><body>Job</body></html>';
}

beforeEach(fn () => Http::fake());

it('creates a listing from a saved page with structured data and makes no request', function (): void {
    $check = app(ImportSavedPage::class)->handle(savedPosting(), 'https://careers.example/jobs/1');
    $job = Opportunity::query()->firstOrFail();
    expect($job->company)->toBe('Acme')->and($job->title)->toBe('Senior PHP Engineer')->and($job->application_url)->toBe('https://careers.example/jobs/1')
        ->and($job->source)->toBe(__('jobs.saved_import_source'))->and($job->link_status)->toBeNull()->and($check->kind)->toBe(LinkCheck::KIND_SAVED);
    Http::assertNothingSent();
});

it('reads an MHTML archive and fills only the empty fields of the chosen job', function (): void {
    $html = quoted_printable_encode(savedPosting());
    $mhtml = "From: <Saved by browser>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"----X\"\r\n\r\n------X\r\nContent-Type: text/html\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{$html}\r\n------X--\r\n";
    $job = Opportunity::factory()->create(['title' => 'Keep me', 'location' => null]);
    app(ImportSavedPage::class)->handle($mhtml, null, $job);
    expect($job->refresh()->title)->toBe('Keep me')->and($job->location)->toBe('Austin, TX')->and(Opportunity::query()->count())->toBe(1);
});

it('keeps pasted text without structured data and queues it for the AI step', function (): void {
    $check = app(ImportSavedPage::class)->handle("Staff Engineer at Globex\nRemote. Requirements: Laravel, 8 years.");
    expect($check->page_text)->toContain('Globex')->and($check->status)->toBe(LinkCheckStatus::Done)->and($check->message)->toBe(__('jobs.saved_queued_for_ai'))
        ->and(AiRequest::query()->where('link_check_id', $check->id)->count())->toBe(1)->and(Opportunity::query()->count())->toBe(0);
});

it('rejects empty content', function (): void {
    expect(fn () => app(ImportSavedPage::class)->handle("  \n "))->toThrow(InvalidArgumentException::class, __('jobs.saved_empty'));
});

it('imports through the import page from a file or a text area and rejects bad input', function (): void {
    Storage::fake('local');
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('job.html', savedPosting()))
        ->call('loadUpload')->assertHasNoErrors();
    expect(Opportunity::query()->where('company', 'Acme')->count())->toBe(1);

    Livewire::test('pages::import')->set('savedText', 'Some job text')->call('importSaved')->assertHasNoErrors();
    expect(AiRequest::query()->count())->toBe(1);

    Livewire::test('pages::import')->call('importSaved')->assertHasErrors(['savedText']);
    Livewire::test('pages::import')->set('savedText', 'x')->set('savedUrl', 'javascript:alert(1)')->call('importSaved')->assertHasErrors(['savedUrl']);
    Livewire::test('pages::import')->set('savedText', 'x')->set('savedJob', '9999')->call('importSaved')->assertHasErrors(['savedJob']);
});

it('routes a csv file to the csv flow and not the saved-page import', function (): void {
    Storage::fake('local');
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('jobs.csv', "company,title\nAcme,Dev\n"))
        ->call('loadUpload')->assertHasNoErrors()->assertSet('headers', ['company', 'title']);
    expect(Opportunity::query()->count())->toBe(0)->and(AiRequest::query()->count())->toBe(0);
});

it('opens the import page with the job from the detail page link preselected', function (): void {
    $job = Opportunity::factory()->create();
    $archived = Opportunity::factory()->create(['archived_at' => now()]);
    $this->get('/opportunities/'.$job->id)->assertOk()->assertSee('/import?job='.$job->id.'#saved', false);
    $this->get('/opportunities/'.$archived->id)->assertOk()->assertDontSee('/import?job='.$archived->id, false);
    Livewire::withQueryParams(['job' => $job->id])->test('pages::import')->assertSet('savedJob', (string) $job->id);
    Livewire::withQueryParams(['job' => $archived->id])->test('pages::import')->assertSet('savedJob', '');
    Livewire::withQueryParams(['job' => 'junk'])->test('pages::import')->assertSet('savedJob', '');
});
