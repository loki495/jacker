<?php

declare(strict_types=1);

use App\Actions\UpdateJobEmbeddings;
use App\Enums\EmbeddingKind;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\ContentEmbedding;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Support\AgentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;

uses(RefreshDatabase::class);

it('opens the letter tab for ?materials=letter and falls back to the resume tab for anything else', function (): void {
    $job = Opportunity::factory()->create();

    Livewire::withQueryParams(['materials' => 'letter'])->test('pages::detail', ['opportunity' => $job])->assertSet('tab', 'letter')->assertSee(__('letter.body'));
    Livewire::withQueryParams(['materials' => 'bogus'])->test('pages::detail', ['opportunity' => $job])->assertSet('tab', 'resume')->assertSee(__('materials.resume_body'));
    Livewire::test('pages::detail', ['opportunity' => $job])->assertSet('tab', 'resume');

    $this->get('/opportunities/'.$job->id.'?materials=letter')->assertOk()->assertSee('id="materials-letter"', false)->assertDontSee('id="materials-resume"', false);
    $this->get('/opportunities/'.$job->id)->assertOk()->assertSee('id="materials-resume"', false);
});

it('links related past letters to the letter tab and related jobs to the plain page', function (): void {
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();
    config(['embeddings.dimensions' => 2]);
    Setting::query()->create(['key' => 'embeddings.enabled', 'value' => ['v' => true]]);
    $job = Opportunity::factory()->create();
    $other = Opportunity::factory()->create(['company' => 'Related Co']);
    CoverLetter::query()->create(['opportunity_id' => $other->id, 'body' => 'An earlier letter']);
    foreach ([$job, $other] as $opportunity) {
        foreach ([EmbeddingKind::Job, EmbeddingKind::Letter] as $kind) {
            if ($kind === EmbeddingKind::Letter && $opportunity->is($job)) {
                continue;
            }
            ContentEmbedding::query()->create([
                'opportunity_id' => $opportunity->id, 'kind' => $kind->value, 'source_hash' => hash('sha256', UpdateJobEmbeddings::source($opportunity->fresh(), $kind)),
                'provider' => config('embeddings.provider'), 'model' => config('embeddings.model'), 'dimensions' => 2, 'vector' => [1.0, 0.5],
            ]);
        }
    }

    $html = $this->get('/opportunities/'.$job->id)->assertOk()->getContent();

    Embeddings::assertNothingGenerated();
    expect($html)->toContain('href="/opportunities/'.$other->id.'?materials=letter#materials"')->toContain('href="/opportunities/'.$other->id.'"');
});

it('shows the private search panel and button only while the worker is online or a run is pending', function (): void {
    Livewire::test('pages::job-finder')->assertDontSee(__('scrapers.agent_title'));

    AgentWorker::register();
    Livewire::test('pages::job-finder')->assertSee(__('scrapers.agent_title'))->assertSee(__('scrapers.agent_run'));

    $this->travel(AgentWorker::STALE + 1)->seconds();
    Livewire::test('pages::job-finder')->assertDontSee(__('scrapers.agent_title'));
});

it('lists the latest private search run with its status and message and polls only while it is pending', function (): void {
    AgentWorker::register();
    AiRequest::query()->create(['kind' => AiRequest::KIND_SCRAPE, 'driver' => 'bridge', 'agent' => 'claude', 'status' => LinkCheckStatus::Done, 'message' => 'Imported 4, skipped 1.']);

    $idle = Livewire::test('pages::job-finder')->assertSee('Imported 4, skipped 1.')->assertSee(LinkCheckStatus::Done->label());
    expect($idle->html())->not->toContain('wire:poll.5s');

    $page = Livewire::test('pages::job-finder')->call('agentScrape')->assertSee(__('scrapers.agent_queued'));
    expect(AiRequest::query()->where('kind', AiRequest::KIND_SCRAPE)->count())->toBe(2)->and($page->html())->toContain('wire:poll.5s')->toContain('disabled');
    $page->call('agentScrape')->assertSee(__('scrapers.agent_unavailable'));
    expect(AiRequest::query()->where('kind', AiRequest::KIND_SCRAPE)->count())->toBe(2);
});

it('keeps showing a pending private search after the worker goes offline, with the button disabled', function (): void {
    AiRequest::query()->create(['kind' => AiRequest::KIND_SCRAPE, 'driver' => 'bridge', 'agent' => 'claude', 'status' => LinkCheckStatus::Queued]);

    $html = Livewire::test('pages::job-finder')->assertSee(__('scrapers.agent_title'))->html();

    expect($html)->toContain('disabled')->toContain(LinkCheckStatus::Queued->label());
});

it('redirects the old link check and scoring pages to the queue kinds', function (): void {
    $this->get('/link-checks')->assertRedirect('/queue?kind=links');
    $this->get('/scoring')->assertRedirect('/queue?kind=grading');

    Livewire::withQueryParams(['kind' => 'links'])->test('pages::queue')->assertSet('kind', 'links');
    Livewire::withQueryParams(['kind' => 'grading'])->test('pages::queue')->assertSet('kind', 'grading');
    $this->get('/queue?kind=links')->assertOk();
});

it('wires the mobile menu to a toggle button and closes it with Escape', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('x-data="{ menu: false }"')->toContain('x-on:keydown.escape.window="menu = false"')
        ->toContain('x-on:click="menu = ! menu"')->toContain('x-bind:aria-expanded="menu"')->toContain('x-bind:class="{ open: menu }"');
});

it('points Preview and Download at the picked version and drops them when none is picked', function (): void {
    $job = Opportunity::factory()->create();
    $page = Livewire::test('pages::detail', ['opportunity' => $job])->assertDontSee(__('materials.preview'))
        ->set('resumeBody', "## Skills\n- PHP")->call('saveResume')->assertSet('resumeVersion', 'edited');

    $view = route('opportunities.material', [$job, 'resume', 'edited']);
    $download = route('opportunities.material', [$job, 'resume', 'edited', 'download' => 1]);
    $html = $page->html();
    expect($html)->toContain('href="'.$view.'"')->toContain('href="'.str_replace('&', '&amp;', $download).'"')->toContain('target="_blank"');

    $page->set('resumeVersion', 'none');
    expect($page->html())->not->toContain('href="'.$view.'"');
    $page->assertSee(__('materials.resume_none_note'));
});

it('renders what was saved: the edited letter and resume come back as PDFs with that text', function (): void {
    $job = Opportunity::factory()->create();
    Livewire::test('pages::detail', ['opportunity' => $job])
        ->set('resumeBody', "## Skills\n- Zebra handling")->call('saveResume')
        ->set('tab', 'letter')->set('letterBody', "Dear Zebra team,\n\nHire me.")->call('saveLetter');

    $resume = $this->get(route('opportunities.material', [$job, 'resume', 'edited', 'download' => 1]))->assertOk();
    $letter = $this->get(route('opportunities.material', [$job, 'letter', 'edited']))->assertOk();

    expect($resume->headers->get('Content-Disposition'))->toStartWith('attachment')->and($letter->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((new Parser)->parseContent($resume->getContent())->getText())->toContain('Zebra handling')
        ->and((new Parser)->parseContent($letter->getContent())->getText())->toContain('Dear Zebra team');
});
