<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the configured defaults for each feature', function (): void {
    $page = Livewire::test('pages::settings')->assertSee('AI processing')->assertSee('Page extraction')->assertSee('Job grading')->assertSee('Cover letter suggestions');
    foreach (AiSettings::FEATURES as $feature) {
        $page->assertSet('aiFeatures.'.$feature, AiSettings::feature($feature));
    }
});

it('saves only known fields without changing other settings', function (): void {
    Setting::query()->create(['key' => 'profile.name', 'value' => ['v' => 'Existing name']]);
    Setting::query()->create(['key' => 'profile.github', 'value' => ['v' => 'https://github.com/example']]);
    Setting::query()->create(['key' => 'scoring.rubric', 'value' => ['v' => 'Existing rubric']]);
    $features = array_fill_keys(AiSettings::FEATURES, ['driver' => 'sdk', 'provider' => 'openai', 'model' => 'example-model', 'extra' => 'ignored']);
    $features['unknown'] = ['driver' => 'sdk', 'provider' => 'openai', 'model' => 'example'];
    Livewire::test('pages::settings')->set('aiFeatures', $features)->call('saveAiSettings')->assertHasNoErrors()->assertSee('AI processing settings saved.');
    foreach (AiSettings::FEATURES as $feature) {
        expect(AiSettings::feature($feature))->toBe(['driver' => 'sdk', 'provider' => 'openai', 'model' => 'example-model']);
    }
    expect(Setting::query()->where('key', 'like', 'ai.%')->count())->toBe(9)
        ->and(Setting::query()->find('profile.name')->value['v'])->toBe('Existing name')
        ->and(Setting::query()->find('profile.github')->value['v'])->toBe('https://github.com/example')
        ->and(Setting::query()->find('scoring.rubric')->value['v'])->toBe('Existing rubric');
});

it('rejects invalid fields without partially saving any feature', function (string $field, string $value): void {
    Setting::query()->create(['key' => 'ai.extract.model', 'value' => ['v' => 'original']]);
    $features = array_fill_keys(AiSettings::FEATURES, ['driver' => 'sdk', 'provider' => 'openai', 'model' => 'new-model']);
    $features['score'][$field] = $value;
    Livewire::test('pages::settings')->set('aiFeatures', $features)->call('saveAiSettings')->assertHasErrors(['aiFeatures.score.'.$field]);
    expect(Setting::query()->where('key', 'like', 'ai.%')->count())->toBe(1)
        ->and(Setting::query()->find('ai.extract.model')->value['v'])->toBe('original');
})->with([['driver', 'bad'], ['provider', 'bad'], ['model', 'bad model']]);
