<?php

declare(strict_types=1);

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function openJob(array $attributes = []): Opportunity
{
    return Opportunity::factory()->create($attributes + ['status' => 'saved', 'has_applied' => false, 'archived_at' => null]);
}

it('binds the board Stage dropdown to the same status property as the table filter', function (): void {
    $saved = openJob(['company' => 'SavedCo']);
    openJob(['company' => 'OfferCo', 'status' => 'offer']);

    $page = Livewire::test('pages::home')->set('view', 'board');
    expect($page->html())->toContain(__('jobs.stage'))->toMatch('/wire:model\.live="status"/');
    $page->set('status', 'offer');
    expect($page->instance()->opportunities->pluck('company')->all())->toBe(['OfferCo']);
    $page->set('view', 'table')->assertSet('status', 'offer');
    expect($page->instance()->opportunities->pluck('company')->all())->toBe(['OfferCo'])->and($saved->company)->toBe('SavedCo');
});

it('togglePage selects then deselects the current page ids', function (): void {
    $a = openJob();
    $b = openJob();

    Livewire::test('pages::home')
        ->call('togglePage')->assertSet('selected', fn (array $ids): bool => collect($ids)->sort()->values()->all() === [(string) $a->id, (string) $b->id])
        ->call('togglePage')->assertSet('selected', []);
});

it('togglePage completes a partial selection instead of clearing it', function (): void {
    $a = openJob();
    $b = openJob();

    $page = Livewire::test('pages::home')->set('selected', [(string) $a->id]);
    $page->call('togglePage');
    expect($page->get('selected'))->toContain((string) $a->id, (string) $b->id)->toHaveCount(2);
});

it('gradeSelected skips jobs that already have a pending score request', function (): void {
    $pending = openJob();
    $fresh = openJob();
    AiRequest::query()->create(['opportunity_id' => $pending->id, 'kind' => AiRequest::KIND_SCORE, 'mode' => 'all', 'agent' => 'claude', 'model' => 'haiku']);

    Livewire::test('pages::home')
        ->set('selected', [(string) $pending->id, (string) $fresh->id])
        ->call('gradeSelected')
        ->assertSet('notice', __('jobs.bulk_graded', ['count' => 1]))
        ->assertSet('selected', []);

    expect(AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->count())->toBe(2)
        ->and(AiRequest::query()->where('opportunity_id', $pending->id)->count())->toBe(1)
        ->and(AiRequest::query()->where('opportunity_id', $fresh->id)->count())->toBe(1);
});

it('queue page polls every 3 seconds and offers Read with AI', function (): void {
    $job = openJob();
    LinkCheck::query()->create(['kind' => 'check', 'url' => 'https://jobs.example/x', 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Done, 'page_text' => 'Some text']);

    Livewire::test('pages::queue')
        ->assertSeeHtml('wire:poll.3s')
        ->assertSeeHtml('wire:click="readWithAi(')
        ->assertSee(__('jobs.ai_extract'));
});

it('readWithAi queues one extraction and reports it, then refuses a duplicate', function (): void {
    $check = LinkCheck::query()->create(['kind' => 'saved', 'url' => 'saved page', 'status' => LinkCheckStatus::Done, 'page_text' => 'Some text']);

    $page = Livewire::test('pages::queue')->call('readWithAi', $check->id)->assertSet('message', __('jobs.ai_queued'));
    expect(AiRequest::query()->where('kind', AiRequest::KIND_EXTRACT)->where('link_check_id', $check->id)->count())->toBe(1);
    $page->call('readWithAi', $check->id)->assertSet('message', __('jobs.ai_not_eligible'));
    $page->call('readWithAi', 99999)->assertSet('message', __('jobs.ai_not_eligible'));
    expect(AiRequest::query()->where('kind', AiRequest::KIND_EXTRACT)->count())->toBe(1);
});

it('renders the mobile menu and the theme switch in the layout', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('class="menu-toggle"')->toContain('aria-controls="site-nav"')
        ->toContain('<nav id="site-nav"')->toContain('x-bind:class="{ open: menu }"')
        ->toContain('class="theme-switch"')->toContain('class="nav-theme"')
        ->and(substr_count($html, 'x-model="$flux.appearance"'))->toBe(2);
});

it('scrolls to the CSV mapping after an upload is loaded', function (): void {
    Livewire::test('pages::import')
        ->set('upload', UploadedFile::fake()->createWithContent('valid.csv', "company,title\nAcme,Role\n"))
        ->call('loadUpload')->assertHasNoErrors()
        ->assertJs("document.getElementById('csv-mapping')?.scrollIntoView({behavior: 'smooth'})");
});
