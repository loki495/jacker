<?php

declare(strict_types=1);

use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Support\Desirability;
use App\Support\ProfileLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('loads the empty workspace editor and handles missing records as 404', function (): void {
    $this->get('/')->assertOk()->assertSee('Your next move.')->assertSee('No opportunities in this view');
    $this->get('/opportunities/create')->assertOk()->assertSee('A new possibility.');
    $this->get('/opportunities/999999')->assertNotFound();
    $this->get('/opportunities/999999/edit')->assertNotFound();
});

it('creates edits and displays all opportunity details with additional fields intact', function (): void {
    Livewire::test('pages::editor')->set('form.company', 'Example Company')->set('form.title', 'Platform Engineer')->set('form.skill_match', 1)->set('extraJson', '{"Team":"Platform"}')->call('save')->assertHasNoErrors();
    $job = Opportunity::query()->sole();
    $this->get('/opportunities/'.$job->id)->assertOk()->assertSee('Platform Engineer')->assertSee('Example Company')->assertSee('Team')->assertSee('Platform');
    Livewire::test('pages::editor', ['opportunity' => $job])->set('form.notes', 'A thoughtful next move')->call('save')->assertHasNoErrors()->assertRedirect('/opportunities/'.$job->id);
    expect($job->refresh()->notes)->toBe('A thoughtful next move')->and($job->extra_fields)->toBe(['Team' => 'Platform']);
});

it('surfaces form validation and invalid json as handled errors', function (): void {
    Livewire::test('pages::editor')->set('form.company', '')->call('save')->assertHasErrors(['form.company' => __('validation.required', ['attribute' => 'company'])])->assertStatus(200);
    Livewire::test('pages::editor')->set('form.company', 'Example')->set('form.skill_match', 0)->set('form.application_url', 'javascript:bad')->call('save')->assertHasErrors(['form.skill_match', 'form.application_url'])->assertStatus(200);
    Livewire::test('pages::editor')->set('extraJson', '{broken')->call('save')->assertHasErrors(['extraJson'])->assertStatus(200);
    expect(Opportunity::query()->count())->toBe(0);
});

it('filters and ranks opportunities without showing archived jobs by default', function (): void {
    $low = Opportunity::factory()->create(['company' => 'Low Priority', 'desirability' => 1, 'skill_match' => 5]);
    $high = Opportunity::factory()->create(['company' => 'High Priority', 'desirability' => 5, 'skill_match' => 1]);
    Opportunity::factory()->create(['company' => 'Unscored', 'desirability' => null]);
    Opportunity::factory()->create(['company' => 'Archived Company', 'archived_at' => now()]);
    Opportunity::factory()->create(['company' => 'Applied Company', 'status' => 'applied', 'has_applied' => true, 'follow_up_at' => today()->subDay()]);
    $component = Livewire::test('pages::home')->assertSeeInOrder(['High Priority', 'Low Priority'])->assertDontSee('Archived Company');
    $component->set('search', 'High Priority')->assertSee('High Priority');
    expect($component->instance()->opportunities->pluck('id')->all())->toBe([$high->id]);
    $component->set('search', '')->set('status', 'applied');
    expect($component->instance()->opportunities->total())->toBe(1);
    $component->set('status', '')->set('due', true);
    expect($component->instance()->opportunities->total())->toBe(1);
    $component->set('due', false)->set('archive', 'archived')->assertSee('Archived Company');
    expect($component->instance()->opportunities->total())->toBe(1);
});

it('changes pipeline status and archives restores from the detail page', function (): void {
    $job = Opportunity::factory()->create();
    Livewire::test('pages::home')->call('changeStatus', $job->id, 'screening')->assertHasNoErrors();
    expect($job->refresh()->has_applied)->toBeTrue()->and($job->status->value)->toBe('screening');
    Livewire::test('pages::detail', ['opportunity' => $job])->call('archive')->assertHasNoErrors();
    expect($job->refresh()->archived_at)->not->toBeNull();
    Livewire::test('pages::detail', ['opportunity' => $job])->call('archive');
    expect($job->refresh()->archived_at)->toBeNull();
    Livewire::test('pages::home')->call('changeStatus', $job->id, 'invalid')->assertHasErrors(['status'])->assertStatus(200);
    expect($job->refresh()->status->value)->toBe('screening');
});

it('sorts independently by either nullable ranking', function (): void {
    $fit = Opportunity::factory()->create(['skill_match' => 5, 'desirability' => 1]);
    $want = Opportunity::factory()->create(['skill_match' => 1, 'desirability' => 5]);
    $unknown = Opportunity::factory()->create(['skill_match' => null, 'desirability' => null]);
    $component = Livewire::test('pages::home')->set('sort', 'skill_match');
    expect($component->instance()->opportunities->pluck('id')->all())->toBe([$fit->id, $want->id, $unknown->id]);
    $component->set('sort', 'desirability');
    expect($component->instance()->opportunities->pluck('id')->all())->toBe([$want->id, $fit->id, $unknown->id]);
});

it('edits named additional fields and rejects missing or duplicate field names', function (): void {
    $component = Livewire::test('pages::editor')->set('form.company', 'Extra Fields')->call('addExtraField')->set('extraFields.0.key', 'Team')->set('extraFields.0.value', 'Platform')->call('save')->assertHasNoErrors();
    expect(Opportunity::query()->sole()->extra_fields)->toBe(['Team' => 'Platform']);
    Livewire::test('pages::editor')->set('form.company', 'Invalid')->call('addExtraField')->set('extraFields.0.value', 'Missing key')->call('save')->assertHasErrors(['extraFields.0.key' => 'required'])->assertStatus(200);
    Livewire::test('pages::editor')->set('form.company', 'Duplicate')->call('addExtraField')->call('addExtraField')->set('extraFields.0.key', 'Team')->set('extraFields.1.key', 'Team')->call('save')->assertHasErrors(['extraFields.1.key' => 'distinct'])->assertStatus(200);
    $job = Opportunity::query()->sole();
    Livewire::test('pages::editor', ['opportunity' => $job])->call('removeExtraField', 0)->call('save')->assertHasNoErrors();
    expect($job->refresh()->extra_fields)->toBe([]);
});

it('preserves imported fields with numeric names during ordinary edits', function (): void {
    $job = Opportunity::factory()->create(['extra_fields' => ['0' => 'Imported text']]);
    Livewire::test('pages::editor', ['opportunity' => $job])->set('form.notes', 'Updated')->call('save')->assertHasNoErrors();
    expect($job->refresh()->extra_fields)->toBe(['0' => 'Imported text']);
});

it('defaults to the combined fit and want score with unscored jobs last', function (): void {
    $both = Opportunity::factory()->create(['skill_match' => 4, 'desirability' => 4]);
    $lopsided = Opportunity::factory()->create(['skill_match' => 5, 'desirability' => 1]);
    $unknown = Opportunity::factory()->create(['skill_match' => null, 'desirability' => null]);
    $component = Livewire::test('pages::home');
    expect($component->get('sort'))->toBe('rank')
        ->and($component->instance()->opportunities->pluck('id')->all())->toBe([$both->id, $lopsided->id, $unknown->id]);
});

it('formats pay and splits list fields for people', function (): void {
    $job = Opportunity::factory()->make(['salary_min' => '125000.00', 'salary_max' => '165000.00', 'currency' => 'USD', 'pay_period' => 'year', 'benefits' => "Medical; dental\n- 401(k) ;; ", 'requirements' => null]);
    expect($job->payRange())->toBe('$125,000 – $165,000 per year')
        ->and($job->listItems('benefits'))->toBe(['Medical', 'dental', '401(k)'])
        ->and($job->listItems('requirements'))->toBe([]);
    expect(Opportunity::factory()->make(['salary_min' => null, 'salary_max' => null])->payRange())->toBeNull();
    expect(Opportunity::factory()->make(['salary_min' => '90000.00', 'salary_max' => '90000.00', 'currency' => 'CAD', 'pay_period' => 'unspecified'])->payRange())->toBe('CAD 90,000');
});

it('renders readable detail sections including empty ones', function (): void {
    $job = Opportunity::factory()->create(['salary_min' => '100000.00', 'salary_max' => '120000.00', 'currency' => 'USD', 'pay_period' => 'year', 'benefits' => 'Medical; dental', 'requirements' => null]);
    Livewire::test('pages::detail', ['opportunity' => $job])->assertSee('$100,000 – $120,000 per year')->assertSeeHtml('<li>Medical</li>')->assertSee(__('jobs.nothing_added'));
});

it('shows the open application link under the title only for web links', function (): void {
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/apply/1']);
    $html = Livewire::test('pages::detail', ['opportunity' => $job])->html();
    expect(strpos($html, 'jobs.example/apply/1'))->toBeLessThan(strpos($html, 'detail-grid'))->and($html)->toContain(__('jobs.open_application_url'));

    $unsafe = Opportunity::factory()->create(['application_url' => 'javascript:alert(1)']);
    expect(Livewire::test('pages::detail', ['opportunity' => $unsafe])->html())->not->toContain('javascript:alert(1)');
});

it('hides closed jobs by default and offers open and all status filters', function (): void {
    Opportunity::factory()->create(['company' => 'Fresh Co', 'status' => 'saved']);
    Opportunity::factory()->create(['company' => 'Applied Co', 'status' => 'applied', 'has_applied' => true]);
    Opportunity::factory()->create(['company' => 'Closed Co', 'status' => 'closed']);
    $component = Livewire::test('pages::home')->assertSet('status', 'open_unapplied')->assertSee('Fresh Co')->assertDontSee('Applied Co')->assertDontSee('Closed Co');
    $component->set('status', 'open')->assertSee('Fresh Co')->assertSee('Applied Co')->assertDontSee('Closed Co');
    $component->set('status', '')->assertSee('Closed Co');
    $component->set('status', 'closed');
    expect($component->instance()->opportunities->pluck('company')->all())->toBe(['Closed Co']);
    $component->set('status', 'bogus')->assertStatus(200)->assertSee('Closed Co');
});

it('filters the pipeline from the summary cards', function (): void {
    Opportunity::factory()->create(['company' => 'Rejected Co', 'status' => 'rejected', 'has_applied' => true]);
    Opportunity::factory()->create(['company' => 'Screening Co', 'status' => 'screening', 'has_applied' => true]);
    Opportunity::factory()->create(['company' => 'Offer Co', 'status' => 'offer', 'has_applied' => true]);
    Opportunity::factory()->create(['company' => 'Due Co', 'status' => 'applied', 'has_applied' => true, 'follow_up_at' => today()]);
    Opportunity::factory()->create(['company' => 'Inbound Co', 'status' => 'contacted']);
    Opportunity::factory()->create(['company' => 'Archived Offer Co', 'status' => 'offer', 'archived_at' => now()]);
    Livewire::test('pages::home')->call('showMetric', 'in_progress')->assertSee('Screening Co')->assertDontSee('Inbound Co')->assertSee('Due Co')->assertDontSee('Offer Co')->assertDontSee('Rejected Co')
        ->call('showMetric', 'offers')->assertSee('Offer Co')->assertDontSee('Archived Offer Co')->assertDontSee('Screening Co')
        ->call('showMetric', 'follow_ups')->assertSee('Due Co')->assertDontSee('Screening Co')->assertSet('due', true)
        ->call('showMetric', 'offers')->assertSet('due', false)
        ->call('showMetric', 'screening')->assertSee('Screening Co')->assertSee('Inbound Co')->assertDontSee('Due Co')->assertSet('status', 'recruiter')
        ->call('showMetric', 'total')->assertSet('status', '')->assertSet('due', false)->assertSee('Rejected Co')->assertSee('Offer Co')->assertDontSee('Archived Offer Co');
    Livewire::test('pages::home')->call('showMetric', 'bogus')->assertStatus(422);
});

it('closes and link-checks selected jobs in bulk', function (): void {
    Queue::fake();
    $a = Opportunity::factory()->create(['status' => 'saved', 'application_url' => 'https://jobs.example/a']);
    $b = Opportunity::factory()->create(['status' => 'saved', 'application_url' => 'https://jobs.example/b']);
    $c = Opportunity::factory()->create(['status' => 'saved']);

    Livewire::test('pages::home')->set('selected', [(string) $a->id, (string) $b->id])->call('checkSelected')->assertSet('selected', [])->assertSee('Queued 2 link checks.');
    expect(LinkCheck::query()->count())->toBe(2);

    Livewire::test('pages::home')->set('selected', [(string) $a->id, (string) $b->id])->call('closeSelected')->assertSee('Closed 2 jobs.');
    expect($a->refresh()->status->value)->toBe('closed')->and($b->refresh()->status->value)->toBe('closed')->and($c->refresh()->status->value)->toBe('saved');
});

it('queues AI grading for selected jobs and uses saved grading criteria', function (): void {
    $a = Opportunity::factory()->create(['status' => 'saved']);
    $b = Opportunity::factory()->create(['status' => 'saved']);
    Livewire::test('pages::home')->set('selected', [(string) $a->id, (string) $b->id])->call('gradeSelected')->assertSee('Queued 2 for AI grading.');
    expect(AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->count())->toBe(2);

    $remote = Opportunity::factory()->create(['work_arrangement' => 'remote', 'salary_min' => null, 'salary_max' => null]);
    $before = Desirability::score($remote, false);
    Livewire::test('pages::settings')->set('payWeight', '0')->set('workMode.remote', '1')->call('save')->assertHasNoErrors();
    expect($before)->toBe(3)->and(Desirability::score($remote, false))->toBe(1);
    Livewire::test('pages::settings')->set('payBands', '1 = 3')->call('save')->assertHasErrors(['payBands']);
    Livewire::test('pages::settings')->call('resetDefaults');
    expect(Desirability::score($remote, false))->toBe(3);
});

it('shows links, pay, work arrangement and selection on board cards', function (): void {
    Opportunity::factory()->create(['company' => 'Board Co', 'status' => 'saved', 'application_url' => 'https://jobs.example/board', 'work_arrangement' => 'hybrid', 'salary_min' => 90000, 'salary_max' => 110000, 'currency' => 'USD', 'pay_period' => 'year']);
    Livewire::test('pages::home')->set('view', 'board')->set('status', '')->assertSee('Hybrid')->assertSee('90,000')->assertSeeHtml('href="https://jobs.example/board"')->assertSeeHtml('wire:model.live="selected"');
});

it('colours score badges from red at 1 to green at 5 and leaves unscored plain', function (): void {
    $job = Opportunity::factory()->create(['desirability' => 1, 'skill_match' => 5]);
    Opportunity::factory()->create(['desirability' => null, 'skill_match' => null]);
    Livewire::test('pages::home')->assertSeeHtml('score-1')->assertSeeHtml('score-5')->assertSeeHtml('score-none');
    $this->get('/opportunities/'.$job->id)->assertOk()->assertSee('score-1', false)->assertSee('score-5', false);
});

it('tints board cards by status', function (): void {
    Opportunity::factory()->create(['status' => 'offer']);
    Livewire::test('pages::home')->set('view', 'board')->set('status', '')->assertSeeHtml('data-status="offer"');
});

it('saves optional profile links from settings and shows them in the shell', function (): void {
    $this->get('/')->assertDontSee('linkedin.com/in/me', false);
    Livewire::test('pages::settings')->set('linkedin', 'https://www.linkedin.com/in/me')->set('github', 'https://github.com/me')->call('saveProfile')->assertHasNoErrors();
    $this->get('/')->assertSee('href="https://www.linkedin.com/in/me"', false)->assertSee('href="https://github.com/me"', false);

    Livewire::test('pages::settings')->set('github', 'https://evil.example/me')->call('saveProfile')->assertHasErrors(['github'])
        ->set('github', 'http://github.com/me')->call('saveProfile')->assertHasErrors(['github'])
        ->set('github', 'https://notgithub.com/me')->call('saveProfile')->assertHasErrors(['github'])
        ->set('linkedin', '')->set('github', '')->call('saveProfile')->assertHasNoErrors();
    expect(ProfileLinks::all())->toBe([]);
});
