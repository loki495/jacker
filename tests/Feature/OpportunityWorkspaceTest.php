<?php

declare(strict_types=1);

use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
