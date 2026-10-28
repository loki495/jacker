<?php

declare(strict_types=1);

use App\Actions\FindRelatedContent;
use App\Actions\QueueJobEmbeddings;
use App\Actions\UpdateJobEmbeddings;
use App\Enums\EmbeddingKind;
use App\Jobs\ProcessJobEmbeddings;
use App\Models\ContentEmbedding;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['embeddings.dimensions' => 2]);
    Embeddings::fake()->preventStrayEmbeddings();
});

function enableEmbeddings(): void
{
    Setting::query()->updateOrCreate(['key' => 'embeddings.enabled'], ['value' => ['v' => true]]);
}

it('does nothing while disabled', function (): void {
    Queue::fake();
    $job = Opportunity::factory()->create();
    app(UpdateJobEmbeddings::class)->handle($job);
    expect(app(QueueJobEmbeddings::class)->handle())->toBe(0);
    Queue::assertNothingPushed();
    Embeddings::assertNothingGenerated();
});

it('updates changed content, skips unchanged content and removes a deleted letter', function (): void {
    enableEmbeddings();
    Embeddings::fake([[[1, 0]], [[0, 1]], [[1, 1]]])->preventStrayEmbeddings();
    $job = Opportunity::factory()->create();
    $letter = CoverLetter::query()->create(['opportunity_id' => $job->id, 'body' => 'A letter']);
    $action = app(UpdateJobEmbeddings::class);
    $action->handle($job);
    $action->handle($job);
    expect(ContentEmbedding::query()->count())->toBe(2);
    $job->update(['title' => 'New title']);
    $letter->delete();
    $action->handle($job);
    expect(ContentEmbedding::query()->count())->toBe(1)
        ->and(ContentEmbedding::query()->first()->vector)->toBe([1, 1]);
});

it('rejects malformed vectors', function (array $vector): void {
    enableEmbeddings();
    Embeddings::fake([[$vector]])->preventStrayEmbeddings();
    $job = Opportunity::factory()->create();
    expect(fn () => app(UpdateJobEmbeddings::class)->handle($job))->toThrow(RuntimeException::class);
    expect(ContentEmbedding::query()->count())->toBe(0);
})->with([[[]], [[0, 0]], [[1]], [[INF, 1]], [['bad', 1]]]);

it('surfaces provider failures', function (): void {
    enableEmbeddings();
    Embeddings::fake(fn () => throw new RuntimeException('provider failed'))->preventStrayEmbeddings();
    expect(fn () => app(UpdateJobEmbeddings::class)->handle(Opportunity::factory()->create()))->toThrow(RuntimeException::class, 'provider failed');
});

it('orders related content and excludes stale, incompatible, archived and self vectors', function (): void {
    enableEmbeddings();
    $jobs = Opportunity::factory()->count(6)->create();
    foreach ($jobs as $index => $job) {
        ContentEmbedding::query()->create([
            'opportunity_id' => $job->id, 'kind' => 'job',
            'source_hash' => hash('sha256', UpdateJobEmbeddings::source($job, EmbeddingKind::Job)),
            'provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 2,
            'vector' => match ($index) {
                1 => [1, 1], 2 => [1, 0], 3 => [-1, 0], default => [1, 0]
            },
        ]);
    }
    $jobs[4]->update(['title' => 'stale']);
    $jobs[5]->update(['archived_at' => now()]);
    $results = app(FindRelatedContent::class)->handle($jobs[0], EmbeddingKind::Job);
    expect(array_map(fn (array $row) => $row['opportunity']->id, $results))->toBe([$jobs[2]->id, $jobs[1]->id]);
    ContentEmbedding::query()->where('opportunity_id', $jobs[2]->id)->update(['model' => 'other']);
    expect(app(FindRelatedContent::class)->handle($jobs[0], EmbeddingKind::Job))->toHaveCount(1);
});

it('queues active jobs when enabled', function (): void {
    enableEmbeddings();
    Queue::fake();
    Opportunity::factory()->create();
    Opportunity::factory()->create(['archived_at' => now()]);
    Queue::fake();
    expect(app(QueueJobEmbeddings::class)->handle())->toBe(1);
    Queue::assertPushed(ProcessJobEmbeddings::class, 1);
});

it('compares very small and very large finite vectors without overflow or underflow', function (): void {
    enableEmbeddings();
    $jobs = Opportunity::factory()->count(3)->create();
    foreach ($jobs as $index => $job) {
        ContentEmbedding::query()->create([
            'opportunity_id' => $job->id, 'kind' => 'job',
            'source_hash' => hash('sha256', UpdateJobEmbeddings::source($job, EmbeddingKind::Job)),
            'provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 2,
            'vector' => match ($index) {
                0 => [1e-300, 1e-300], 1 => [1e300, 1e300], default => [1e300, 0]
            },
        ]);
    }
    $results = app(FindRelatedContent::class)->handle($jobs[0], EmbeddingKind::Job);
    expect(array_map(fn (array $row) => $row['opportunity']->id, $results))->toBe([$jobs[1]->id, $jobs[2]->id])
        ->and($results[0]['similarity'])->toBeGreaterThan(0.999)
        ->and($results[1]['similarity'])->toBeGreaterThan(0.7);
    Embeddings::assertNothingGenerated();
});
