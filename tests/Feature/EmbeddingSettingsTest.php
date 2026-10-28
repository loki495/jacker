<?php

declare(strict_types=1);

use App\Jobs\ProcessJobEmbeddings;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Support\EmbeddingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();
});

it('saves the opt-in without calling a provider or indexing existing jobs', function (): void {
    Opportunity::factory()->create();
    Livewire::test('pages::settings')
        ->assertSet('embeddingsEnabled', false)
        ->set('embeddingsEnabled', true)->call('saveEmbeddings')->assertHasNoErrors()
        ->assertSee(__('settings.embeddings_saved'));
    expect(EmbeddingSettings::enabled())->toBeTrue();
    Queue::assertNothingPushed();
    Embeddings::assertNothingGenerated();
    Livewire::test('pages::settings')->set('embeddingsEnabled', false)->call('saveEmbeddings')->assertHasNoErrors();
    expect(EmbeddingSettings::enabled())->toBeFalse();
});

it('indexes only on request after opt-in and does nothing while disabled', function (): void {
    Opportunity::factory()->create();
    $settings = Livewire::test('pages::settings')->call('indexEmbeddings')->assertHasNoErrors();
    Queue::assertNothingPushed();
    $settings->set('embeddingsEnabled', true)->call('saveEmbeddings')->call('indexEmbeddings')->assertHasNoErrors();
    Queue::assertPushed(ProcessJobEmbeddings::class, 1);
    Embeddings::assertNothingGenerated();
});

it('queues relevant saved content changes and ignores score and suggestion updates', function (): void {
    $job = Opportunity::factory()->create();
    $letter = CoverLetter::query()->create(['opportunity_id' => $job->id, 'body' => 'First draft']);
    Livewire::test('pages::settings')->set('embeddingsEnabled', true)->call('saveEmbeddings');
    $job->update(['skill_match' => 5]);
    $letter->update(['suggestions' => 'Clearer opening.']);
    Queue::assertNothingPushed();
    $job->update(['title' => 'Changed role']);
    $letter->update(['body' => 'Second draft']);
    $letter->delete();
    Queue::assertPushed(ProcessJobEmbeddings::class, 3);
    Embeddings::assertNothingGenerated();
});

it('shows similarity sections only when enabled without generating embeddings on a read', function (): void {
    $job = Opportunity::factory()->create();
    Livewire::test('pages::detail', ['opportunity' => $job])->assertDontSee(__('settings.related_jobs'));
    Livewire::test('pages::settings')->set('embeddingsEnabled', true)->call('saveEmbeddings');
    Livewire::test('pages::detail', ['opportunity' => $job])->assertSee(__('settings.related_jobs'))->assertSee(__('settings.related_letters'))->assertSee(__('settings.related_empty'));
    Queue::assertNothingPushed();
    Embeddings::assertNothingGenerated();
});
