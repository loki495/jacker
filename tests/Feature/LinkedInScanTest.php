<?php

declare(strict_types=1);

use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\LinkedInLink;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function linkedinLink(array $attributes = []): LinkedInLink
{
    return LinkedInLink::query()->create(array_merge([
        'job_id' => '4469238554',
        'url' => 'https://www.linkedin.com/jobs/view/4469238554',
        'title' => 'Senior PHP Developer',
        'company' => 'Acme Corp',
        'location' => 'Santa Monica, CA',
        'alert' => 'web developer in Los Angeles Metropolitan Area',
        'status' => LinkedInLink::NEW,
    ], $attributes));
}

it('lists the kept links on their own page and queues the ticked ones', function (): void {
    $first = linkedinLink();
    $second = linkedinLink(['job_id' => '4469238555', 'url' => 'https://www.linkedin.com/jobs/view/4469238555', 'title' => 'Web Developer']);

    Livewire::test('pages::linkedin')
        ->assertSee('Senior PHP Developer')
        ->assertSee('Web Developer')
        ->set('selected', [$first->id])
        ->call('queueSelected')
        ->assertSee(__('jobs.linkedin_status_queued'));

    expect($first->refresh()->status)->toBe(LinkedInLink::QUEUED)->and($second->refresh()->status)->toBe(LinkedInLink::NEW);
});

it('refuses to queue nothing and lets a failed scan be retried', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::FAILED, 'scan_error' => 'login wall']);

    Livewire::test('pages::linkedin')
        ->call('queueSelected')
        ->assertSee(__('jobs.linkedin_nothing_selected'))
        ->set('selected', [$link->id])
        ->call('queueSelected');

    expect($link->refresh()->status)->toBe(LinkedInLink::QUEUED)->and($link->scan_error)->toBeNull();
});

it('dismisses a link from its page', function (): void {
    $link = linkedinLink();

    Livewire::test('pages::linkedin')
        ->call('dismissLink', $link->id)
        ->assertSee(__('jobs.linkedin_dismissed'))
        ->assertDontSee('Senior PHP Developer');

    expect($link->refresh()->status)->toBe(LinkedInLink::DISMISSED);
});

it('claims the oldest queued link once and leaves the rest', function (): void {
    $first = linkedinLink(['status' => LinkedInLink::QUEUED]);
    $second = linkedinLink(['job_id' => '2', 'url' => 'https://www.linkedin.com/jobs/view/2', 'status' => LinkedInLink::QUEUED]);

    $this->artisan('linkedin:claim')
        ->expectsOutputToContain('"id":'.$first->id)
        ->assertSuccessful();

    expect($first->refresh()->status)->toBe(LinkedInLink::SCANNING)->and($second->refresh()->status)->toBe(LinkedInLink::QUEUED);

    $this->artisan('linkedin:claim')->doesntExpectOutputToContain('"id":'.$first->id)->assertSuccessful();
});

it('creates the listing from the scan and marks the link imported without duplicating it on a re-scan', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::SCANNING]);
    $payload = json_encode([
        'title' => 'Senior PHP Developer', 'company' => 'Acme Corp', 'location' => 'Santa Monica, CA',
        'description' => "Build things.\n\nRequirements: PHP.", 'apply_url' => 'https://acme.test/apply/1',
    ], JSON_UNESCAPED_SLASHES);

    $this->artisan('linkedin:complete', ['id' => $link->id, '--json' => $payload])->assertSuccessful();

    $link->refresh();
    $job = Opportunity::query()->findOrFail($link->opportunity_id);
    expect($link->status)->toBe(LinkedInLink::IMPORTED)->and($link->scanned_at)->not->toBeNull()
        ->and($job->title)->toBe('Senior PHP Developer')->and($job->company)->toBe('Acme Corp')
        ->and($job->application_url)->toBe('https://acme.test/apply/1')
        ->and($job->requirements)->toContain('Build things.')
        ->and($job->extra_fields['LinkedIn posting'])->toBe('https://www.linkedin.com/jobs/view/4469238554')
        ->and($link->apply_url)->toBe('https://acme.test/apply/1');

    $link->update(['status' => LinkedInLink::SCANNING]);
    $this->artisan('linkedin:complete', ['id' => $link->id, '--json' => $payload])->assertSuccessful();
    expect(Opportunity::query()->count())->toBe(1)->and($link->refresh()->opportunity_id)->toBe($job->id);
});

it('marks a link failed with the reason and no listing', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::SCANNING]);

    $this->artisan('linkedin:complete', ['id' => $link->id, '--fail' => 'LinkedIn showed the sign-in wall'])->assertSuccessful();

    expect($link->refresh()->status)->toBe(LinkedInLink::FAILED)
        ->and($link->scan_error)->toBe('LinkedIn showed the sign-in wall')
        ->and(Opportunity::query()->count())->toBe(0);
});

it('marks a link failed when the page had no title or company', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::SCANNING]);

    $this->artisan('linkedin:complete', ['id' => $link->id, '--json' => json_encode(['company' => 'Acme Corp'])])->assertSuccessful();

    expect($link->refresh()->status)->toBe(LinkedInLink::FAILED)->and($link->scan_error)->toContain('title and company');
});

it('queues the AI split of the scanned description and notes Easy Apply when there is no external link', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::SCANNING]);
    $payload = json_encode(['title' => 'Dev', 'company' => 'Acme', 'description' => "About us.\nBenefits: pizza.", 'apply_url' => null, 'easy_apply' => true]);

    $this->artisan('linkedin:complete', ['id' => $link->id, '--json' => $payload])->assertSuccessful();

    $job = Opportunity::query()->findOrFail($link->refresh()->opportunity_id);
    $check = LinkCheck::query()->where('opportunity_id', $job->id)->firstOrFail();
    expect($job->application_url)->toBe($link->url)->and($job->extra_fields['Apply'])->toBe('LinkedIn Easy Apply')
        ->and($check->kind)->toBe(LinkCheck::KIND_SAVED)->and($check->page_text)->toContain('Benefits: pizza.')
        ->and(AiRequest::query()->where('link_check_id', $check->id)->where('kind', AiRequest::KIND_EXTRACT)->exists())->toBeTrue();
});

it('queues a rescan for an imported link that has no external apply link', function (): void {
    $link = linkedinLink(['status' => LinkedInLink::IMPORTED]);
    Livewire::test('pages::linkedin')->call('rescan', $link->id);
    expect($link->refresh()->status)->toBe(LinkedInLink::QUEUED);
});
