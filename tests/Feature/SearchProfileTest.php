<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\CoverLetterSettings;
use App\Support\ProfileLinks;
use App\Support\ScoringSettings;
use App\Support\SearchProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.token' => 'secret-token', 'searchprofile.dibs_id' => null]));

it('requires the token for the profile endpoint', function (): void {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});

it('reports an unconfigured profile with the grading criteria', function (): void {
    $response = $this->withToken('secret-token')->getJson('/api/v1/profile')->assertOk();
    expect($response->json('configured'))->toBeFalse()->and($response->json('about'))->toBe('')
        ->and($response->json('updated_at'))->toBeNull()
        ->and($response->json('scoring.pay_bands.0'))->toHaveKeys(['annual_usd_at_least', 'points'])
        ->and($response->json('scoring.skill_rubric'))->toContain('Laravel')
        ->and($response->json('accepted_regions'))->toContain('worldwide');
});

it('serves the saved text, name, location and links but never email, phone or resumes', function (): void {
    SearchProfile::save("  Remote Laravel roles.\nNo crypto.  ", 'dibs:12');
    CoverLetterSettings::save('profile.name', 'Ada Lovelace');
    CoverLetterSettings::save('profile.location', 'London');
    CoverLetterSettings::save('profile.email', 'ada@example.com');
    CoverLetterSettings::save('profile.phone', '555-0100');
    ProfileLinks::save('github', 'https://github.com/ada');
    $response = $this->withToken('secret-token')->getJson('/api/v1/profile')->assertOk();
    expect($response->json('about'))->toBe("Remote Laravel roles.\nNo crypto.")
        ->and($response->json('source'))->toBe('dibs:12')->and($response->json('configured'))->toBeTrue()
        ->and($response->json('name'))->toBe('Ada Lovelace')->and($response->json('location'))->toBe('London')
        ->and($response->json('links'))->toBe(['github' => 'https://github.com/ada'])
        ->and($response->json('updated_at'))->not->toBeNull()
        ->and($response->getContent())->not->toContain('ada@example.com', '555-0100');
});

it('changes the version when the text or the grading criteria change', function (): void {
    $get = fn (): string => $this->withToken('secret-token')->getJson('/api/v1/profile')->json('version');
    $first = $get();
    expect($get())->toBe($first);
    SearchProfile::save('Something new');
    $second = $get();
    expect($second)->not->toBe($first);
    ScoringSettings::save('pay_weight', 0.9);
    expect($get())->not->toBe($second);
});

it('is listed in the info description', function (): void {
    $paths = array_column($this->withToken('secret-token')->getJson('/api/v1/info')->json('endpoints'), 'path');
    expect($paths)->toContain('/api/v1/profile');
});

it('replaces and clears the text with profile:set', function (): void {
    $this->artisan('profile:set --source=dibs:7')->expectsOutputToContain('cleared')->assertSuccessful();
    expect(SearchProfile::text())->toBe('')->and(Setting::query()->count())->toBe(0);
    SearchProfile::save('old', 'dibs:7');
    SearchProfile::save('');
    expect(SearchProfile::text())->toBe('')->and(SearchProfile::source())->toBe('settings');
});

it('rejects a bad source label in profile:set and saves nothing', function (): void {
    $this->artisan('profile:set --source="Bad Source"')->assertFailed();
    expect(SearchProfile::text())->toBe('')->and(Setting::query()->count())->toBe(0);
});

it('saves the text from the Settings page and validates its length', function (): void {
    Livewire::test('pages::settings')->set('searchProfile', 'Laravel, remote, US.')->call('saveSearchProfile')->assertHasNoErrors();
    expect(SearchProfile::text())->toBe('Laravel, remote, US.');
    Livewire::test('pages::settings')->set('searchProfile', str_repeat('x', 20001))->call('saveSearchProfile')->assertHasErrors(['searchProfile']);
    Livewire::test('pages::settings')->set('searchProfile', '')->call('saveSearchProfile');
    expect(SearchProfile::text())->toBe('');
});

it('shows the text read-only and refuses edits while a Dibs record manages it', function (): void {
    SearchProfile::save('From Dibs.', 'dibs:42');
    config(['searchprofile.dibs_id' => 42]);
    Livewire::test('pages::settings')->assertSee('Dibs record 42')->assertSee('From Dibs.')->assertDontSee('Save search profile')
        ->set('searchProfile', 'hacked')->call('saveSearchProfile')->assertForbidden();
    expect(SearchProfile::text())->toBe('From Dibs.');
});
