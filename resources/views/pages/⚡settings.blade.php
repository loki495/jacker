<?php

declare(strict_types=1);

use App\Enums\WorkArrangement;
use App\Support\ProfileLinks;
use App\Support\ScoringSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $rubric = '';

    public string $payBands = '';

    public string $payWeight = '';

    public string $payUnknown = '';

    public string $easternPenalty = '';

    public array $workMode = [];

    public string $linkedin = '';

    public string $github = '';

    public string $message = '';

    public function mount(): void
    {
        $this->load();
    }

    public function render()
    {
        return $this->view()->title(__('settings.page_title'));
    }

    public function saveProfile(): void
    {
        $rules = [];
        foreach (ProfileLinks::SITES as $site => $host) {
            $rules[$site] = ['nullable', 'string', 'max:255', 'url:https', function (string $attribute, mixed $value, Closure $fail) use ($host): void {
                $actual = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                if ($actual !== $host && ! str_ends_with($actual, '.'.$host)) {
                    $fail(__('settings.profile_invalid', ['host' => $host]));
                }
            }];
        }
        $this->validate($rules);
        foreach (array_keys(ProfileLinks::SITES) as $site) {
            ProfileLinks::save($site, trim($this->{$site}));
        }
        $this->message = __('settings.profile_saved');
    }

    public function save(): void
    {
        $this->validate([
            'rubric' => ['required', 'string', 'max:5000'],
            'payWeight' => ['required', 'numeric', 'between:0,1'],
            'payUnknown' => ['required', 'numeric', 'between:1,5'],
            'easternPenalty' => ['required', 'numeric', 'between:0,4'],
            'workMode.*' => ['required', 'numeric', 'between:1,5'],
            'payBands' => ['required', 'string', 'max:1000'],
        ]);
        $bands = $this->parseBands();
        DB::transaction(function () use ($bands): void {
            ScoringSettings::save('rubric', trim($this->rubric));
            ScoringSettings::save('pay_bands', $bands);
            ScoringSettings::save('pay_weight', (float) $this->payWeight);
            ScoringSettings::save('pay_unknown', (float) $this->payUnknown);
            ScoringSettings::save('eastern_penalty', (float) $this->easternPenalty);
            ScoringSettings::save('work_mode', array_map(floatval(...), $this->workMode));
        });
        $this->load();
        $this->message = __('settings.saved');
    }

    public function resetDefaults(): void
    {
        ScoringSettings::reset();
        $this->load();
        $this->resetValidation();
        $this->message = __('settings.reset_done');
    }

    /** @return array<int, int> floor => points, highest floor first */
    private function parseBands(): array
    {
        $bands = [];
        foreach (preg_split('/\R/', trim($this->payBands)) ?: [] as $line) {
            if (! preg_match('/^\s*(\d+)\s*=\s*([1-5])\s*$/', $line, $match)) {
                throw ValidationException::withMessages(['payBands' => __('settings.bands_invalid')]);
            }
            $bands[(int) $match[1]] = (int) $match[2];
        }
        if (! array_key_exists(0, $bands)) {
            throw ValidationException::withMessages(['payBands' => __('settings.bands_floor')]);
        }
        krsort($bands);

        return $bands;
    }

    private function load(): void
    {
        foreach (ProfileLinks::all() as $site => $url) {
            $this->{$site} = $url;
        }
        $this->rubric = (string) ScoringSettings::get('rubric');
        $this->payWeight = (string) ScoringSettings::get('pay_weight');
        $this->payUnknown = (string) ScoringSettings::get('pay_unknown');
        $this->easternPenalty = (string) ScoringSettings::get('eastern_penalty');
        $this->workMode = array_map(strval(...), ScoringSettings::get('work_mode'));
        $this->payBands = collect(ScoringSettings::get('pay_bands'))->map(fn ($points, $floor): string => $floor.' = '.$points)->implode("\n");
    }
}; ?>

<div class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('settings.heading') }}</flux:heading>
        <flux:text class="mt-2">{{ __('settings.intro') }}</flux:text>
    </div>
    @if ($message)<p role="status" class="rounded-xl bg-teal-50 p-4 text-teal-900">{{ $message }}</p>@endif
    <form wire:submit="saveProfile" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('settings.profile_title') }}</flux:heading>
        <flux:text>{{ __('settings.profile_intro') }}</flux:text>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><flux:input wire:model="linkedin" type="url" :label="__('settings.linkedin')" placeholder="https://www.linkedin.com/in/..." />@error('linkedin') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror</div>
            <div><flux:input wire:model="github" type="url" :label="__('settings.github')" placeholder="https://github.com/..." />@error('github') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <flux:button type="submit" variant="primary">{{ __('settings.profile_save') }}</flux:button>
    </form>
    <flux:heading size="lg" level="2">{{ __('settings.grading_title') }}</flux:heading>
    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4 rounded-2xl border border-zinc-200 p-5">
            <flux:heading size="lg" level="2">{{ __('settings.fit_title') }}</flux:heading>
            <flux:textarea wire:model="rubric" rows="10" :label="__('settings.rubric')" :description="__('settings.rubric_hint')" />
            @error('rubric') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
        </section>
        <section class="space-y-4 rounded-2xl border border-zinc-200 p-5">
            <flux:heading size="lg" level="2">{{ __('settings.desirability_title') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="payWeight" inputmode="decimal" :label="__('settings.pay_weight')" :description="__('settings.pay_weight_hint')" />
                <flux:input wire:model="payUnknown" inputmode="decimal" :label="__('settings.pay_unknown')" />
                <flux:input wire:model="easternPenalty" inputmode="decimal" :label="__('settings.eastern_penalty')" />
            </div>
            @foreach (['payWeight', 'payUnknown', 'easternPenalty'] as $field) @error($field) <p role="alert" class="text-red-700">{{ $message }}</p> @enderror @endforeach
            <flux:textarea wire:model="payBands" rows="6" :label="__('settings.pay_bands')" :description="__('settings.pay_bands_hint')" />
            @error('payBands') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <flux:heading level="3">{{ __('settings.work_mode') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-4">
                @foreach (WorkArrangement::cases() as $mode)
                    <flux:input wire:model="workMode.{{ $mode->value }}" inputmode="decimal" :label="$mode->label()" wire:key="mode-{{ $mode->value }}" />
                @endforeach
            </div>
            @error('workMode.*') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
        </section>
        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('settings.save') }}</flux:button>
            <flux:button type="button" wire:click="resetDefaults" wire:confirm="{{ __('settings.reset_confirm') }}">{{ __('settings.reset') }}</flux:button>
        </div>
    </form>
</div>
