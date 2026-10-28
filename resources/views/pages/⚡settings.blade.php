<?php

declare(strict_types=1);

use App\Actions\QueueJobEmbeddings;
use App\Actions\SaveAiSettings;
use App\Actions\SaveEmbeddingSettings;
use App\Enums\AiDriver;
use App\Enums\WorkArrangement;
use App\Support\AiSettings;
use App\Support\CoverLetterSettings;
use App\Support\EmbeddingSettings;
use App\Support\ProfileLinks;
use App\Support\ScoringSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $location = '';

    public string $coverAgent = '';

    public string $coverModel = '';

    public string $coverThinking = '';

    public array $aiFeatures = [];

    public bool $embeddingsEnabled = false;

    public string $message = '';

    public function mount(): void
    {
        $this->load();
    }

    public function render()
    {
        return $this->view()->title(__('settings.page_title'));
    }

    public function saveAiSettings(SaveAiSettings $save): void
    {
        $save->handle($this->aiFeatures);
        $this->message = __('settings.ai_saved');
    }

    public function saveEmbeddings(SaveEmbeddingSettings $save): void
    {
        $this->validate(['embeddingsEnabled' => ['boolean']]);
        $save->handle($this->embeddingsEnabled);
        $this->message = __('settings.embeddings_saved');
    }

    public function indexEmbeddings(QueueJobEmbeddings $queue): void
    {
        $this->message = __('settings.embeddings_queued', ['count' => $queue->handle()]);
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

    public function saveContact(): void
    {
        $this->validate(['name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:60'], 'location' => ['nullable', 'string', 'max:120']]);
        DB::transaction(function (): void {
            foreach (CoverLetterSettings::CONTACT as $field) {
                CoverLetterSettings::save('profile.'.$field, trim($this->{$field}));
            }
        });
        $this->message = __('letter.contact_saved');
    }

    public function saveCoverAi(): void
    {
        $this->validate([
            'coverAgent' => ['required', Rule::in(CoverLetterSettings::AGENTS)],
            'coverModel' => ['required', 'string', 'max:100', 'regex:'.CoverLetterSettings::MODEL_PATTERN],
            'coverThinking' => ['required', Rule::in(CoverLetterSettings::THINKING)],
        ], ['coverModel.regex' => __('letter.model_invalid')]);
        DB::transaction(function (): void {
            CoverLetterSettings::save('cover.agent', $this->coverAgent);
            CoverLetterSettings::save('cover.model', trim($this->coverModel));
            CoverLetterSettings::save('cover.thinking', $this->coverThinking);
        });
        $this->message = __('letter.ai_saved');
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
        $this->embeddingsEnabled = EmbeddingSettings::enabled();
        foreach (AiSettings::FEATURES as $feature) {
            $this->aiFeatures[$feature] = AiSettings::feature($feature);
        }
        foreach (ProfileLinks::all() as $site => $url) {
            $this->{$site} = $url;
        }
        foreach (CoverLetterSettings::contact() as $field => $value) {
            $this->{$field} = $value;
        }
        ['agent' => $this->coverAgent, 'model' => $this->coverModel, 'thinking' => $this->coverThinking] = CoverLetterSettings::ai();
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
    <form wire:submit="saveContact" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('letter.contact_title') }}</flux:heading>
        <flux:text>{{ __('letter.contact_intro') }}</flux:text>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (CoverLetterSettings::CONTACT as $field)
                <div><flux:input wire:model="{{ $field }}" :label="__('letter.'.$field)" wire:key="contact-{{ $field }}" />@error($field) <p role="alert" class="text-red-700">{{ $message }}</p> @enderror</div>
            @endforeach
        </div>
        <flux:button type="submit" variant="primary">{{ __('letter.contact_save') }}</flux:button>
    </form>
    <form wire:submit="saveAiSettings" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('settings.ai_title') }}</flux:heading>
        <flux:text>{{ __('settings.ai_intro') }}</flux:text>
        @foreach (AiSettings::FEATURES as $feature)
            <fieldset class="space-y-3" wire:key="ai-{{ $feature }}">
                <legend class="font-semibold">{{ __('settings.ai_feature_'.$feature) }}</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="min-w-0">
                        <flux:select wire:model="aiFeatures.{{ $feature }}.driver" :label="__('settings.ai_driver')">
                            <option value="{{ AiDriver::Bridge->value }}">{{ __('settings.ai_bridge') }}</option>
                            <option value="{{ AiDriver::Sdk->value }}">{{ __('settings.ai_sdk') }}</option>
                        </flux:select>
                        @error('aiFeatures.'.$feature.'.driver') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <flux:select wire:model="aiFeatures.{{ $feature }}.provider" :label="__('settings.ai_provider')">
                            @foreach (AiSettings::PROVIDERS as $provider)<option value="{{ $provider }}">{{ ucfirst($provider) }}</option>@endforeach
                        </flux:select>
                        @error('aiFeatures.'.$feature.'.provider') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <flux:input wire:model="aiFeatures.{{ $feature }}.model" :label="__('settings.ai_model')" />
                        @error('aiFeatures.'.$feature.'.model') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endforeach
        <flux:button type="submit" variant="primary">{{ __('settings.ai_save') }}</flux:button>
    </form>
    <form wire:submit="saveCoverAi" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('settings.bridge_letter_title') }}</flux:heading>
        <flux:text>{{ __('settings.bridge_letter_intro') }}</flux:text>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select wire:model="coverAgent" :label="__('letter.agent')">@foreach (CoverLetterSettings::AGENTS as $agent)<option value="{{ $agent }}">{{ $agent }}</option>@endforeach</flux:select>
            <flux:input wire:model="coverModel" :label="__('letter.model')" />
            <flux:select wire:model="coverThinking" :label="__('letter.thinking')">@foreach (CoverLetterSettings::THINKING as $level)<option value="{{ $level }}">{{ $level === 'off' ? __('letter.thinking_off') : ucfirst($level) }}</option>@endforeach</flux:select>
        </div>
        @foreach (['coverAgent', 'coverModel', 'coverThinking'] as $field) @error($field) <p role="alert" class="text-red-700">{{ $message }}</p> @enderror @endforeach
        <flux:button type="submit" variant="primary">{{ __('letter.ai_save') }}</flux:button>
    </form>
    <form wire:submit="saveEmbeddings" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('settings.embeddings_title') }}</flux:heading>
        <flux:text>{{ __('settings.embeddings_intro') }}</flux:text>
        <flux:checkbox wire:model="embeddingsEnabled" :label="__('settings.embeddings_enabled')" />
        <div class="flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary">{{ __('settings.embeddings_save') }}</flux:button>
            <flux:button type="button" wire:click="indexEmbeddings" wire:loading.attr="disabled">{{ __('settings.embeddings_index') }}</flux:button>
        </div>
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
