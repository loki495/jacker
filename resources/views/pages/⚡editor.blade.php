<?php

declare(strict_types=1);

use App\Actions\SaveOpportunity;
use App\Models\Opportunity;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public ?int $opportunityId = null;

    public array $form = [];

    public string $extraJson = '{}';

    public array $extraFields = [];

    public bool $editingExtraFields = false;

    public function mount(?Opportunity $opportunity = null): void
    {
        $this->opportunityId = $opportunity?->id;
        $this->form = array_replace(array_fill_keys(['company', 'title', 'company_url', 'application_url', 'source', 'salary_min', 'salary_max', 'salary_notes', 'benefits', 'hours', 'location', 'remote_restrictions', 'responsibilities', 'requirements', 'contact', 'notes', 'skill_match', 'desirability', 'skill_match_notes', 'desirability_notes', 'applied_at', 'deadline', 'follow_up_at'], ''), ['currency' => 'USD', 'status' => 'saved', 'employment_type' => 'unspecified', 'work_arrangement' => 'unspecified', 'pay_period' => 'unspecified', 'has_applied' => false], $opportunity?->attributesToArray() ?? []);
        foreach (['applied_at', 'deadline', 'follow_up_at'] as $field) {
            $this->form[$field] = $opportunity?->{$field}?->format('Y-m-d') ?? '';
        }
        foreach ($opportunity?->extra_fields ?? [] as $key => $value) {
            $this->extraFields[] = ['key' => (string) $key, 'value' => $value ?? ''];
        }
        $this->extraJson = json_encode((object) ($opportunity?->extra_fields ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function addExtraField(): void
    {
        $this->extraFields[] = ['key' => '', 'value' => ''];
        $this->editingExtraFields = true;
    }

    public function removeExtraField(int $index): void
    {
        unset($this->extraFields[$index]);
        $this->extraFields = array_values($this->extraFields);
        $this->editingExtraFields = true;
    }

    public function updatedExtraFields(): void
    {
        $this->editingExtraFields = true;
    }

    public function save(): void
    {
        if ($this->editingExtraFields) {
            $this->validate(['extraFields' => ['array', 'max:100'], 'extraFields.*.key' => ['required', 'string', 'max:255', 'distinct'], 'extraFields.*.value' => ['nullable', 'string', 'max:20000']]);
            $extraValues = [];
            foreach ($this->extraFields as $row) {
                $extraValues[$row['key']] = $row['value'];
            }
            $this->extraJson = json_encode((object) $extraValues, JSON_THROW_ON_ERROR);
        }
        try {
            $extra = json_decode($this->extraJson, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw \Illuminate\Validation\ValidationException::withMessages(['extraJson' => __('jobs.invalid_json')]);
        }
        if (! $extra instanceof stdClass) {
            throw \Illuminate\Validation\ValidationException::withMessages(['extraJson' => __('jobs.invalid_json')]);
        }
        $opportunity = $this->opportunityId ? Opportunity::query()->findOrFail($this->opportunityId) : null;
        try {
            $saved = app(SaveOpportunity::class)->handle(array_replace($this->form, ['extra_fields' => get_object_vars($extra)]), $opportunity);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['form.'.$key] = $messages;
            }
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        session()->flash('message', __('jobs.saved_message'));
        $this->redirect('/opportunities/'.$saved->id, navigate: true);
    }
};
?>

<div class="editor-page">
    <a href="{{ $opportunityId ? '/opportunities/'.$opportunityId : '/' }}" wire:navigate class="back-link">← {{ __('jobs.back') }}</a>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.make_it_yours') }}</div><h1>{{ $opportunityId ? __('jobs.edit_job') : __('jobs.new_job') }}</h1><p>{{ __('jobs.editor_intro') }}</p></div></div>
    <form wire:submit="save" class="editor-form">
        @php
            $sections = [
                'job' => ['company', 'title', 'company_url', 'application_url', 'source', 'location', 'work_arrangement', 'remote_restrictions', 'employment_type', 'hours'],
                'compensation' => ['salary_min', 'salary_max', 'currency', 'pay_period', 'salary_notes', 'benefits'],
                'application' => ['status', 'has_applied', 'applied_at', 'deadline', 'follow_up_at', 'contact'],
                'rankings' => ['desirability', 'desirability_notes', 'skill_match', 'skill_match_notes'],
                'details' => ['responsibilities', 'requirements', 'notes'],
            ];
            $enumFields = ['status' => App\Enums\OpportunityStatus::cases(), 'employment_type' => App\Enums\EmploymentType::cases(), 'work_arrangement' => App\Enums\WorkArrangement::cases(), 'pay_period' => App\Enums\PayPeriod::cases()];
            $textareas = ['salary_notes','benefits','remote_restrictions','contact','desirability_notes','skill_match_notes','responsibilities','requirements','notes'];
        @endphp
        @foreach($sections as $section => $fields)
            <section class="panel form-section"><div class="form-section-heading"><span class="section-number">{{ str_pad((string)$loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><h2>{{ __('jobs.'.$section) }}</h2><p>{{ __('jobs.section_'.$section) }}</p></div></div><div class="form-grid">
                @foreach($fields as $field)
                    <div @class(['wide-field' => in_array($field, $textareas, true)])>
                        @if(isset($enumFields[$field]))
                            <flux:select wire:model="form.{{ $field }}" :label="__('jobs.'.$field)">@foreach($enumFields[$field] as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>
                        @elseif(in_array($field, ['desirability','skill_match'], true))
                            <flux:select wire:model="form.{{ $field }}" :label="__('jobs.'.$field)"><option value="">{{ __('jobs.unscored') }}</option>@foreach(range(1,5) as $score)<option value="{{ $score }}">{{ $score }} / 5</option>@endforeach</flux:select>
                        @elseif($field === 'has_applied')
                            <div class="checkbox-field"><flux:checkbox wire:model="form.has_applied" :label="__('jobs.has_applied')" /><p class="field-hint">{{ __('jobs.unknown_date') }}</p></div>
                        @elseif(in_array($field, $textareas, true))
                            <flux:textarea wire:model="form.{{ $field }}" :label="__('jobs.'.$field)" rows="3" />
                        @else
                            <flux:input wire:model="form.{{ $field }}" :label="__('jobs.'.$field)" :type="in_array($field, ['applied_at','deadline','follow_up_at']) ? 'date' : (in_array($field, ['salary_min','salary_max']) ? 'number' : (in_array($field, ['company_url','application_url']) ? 'url' : 'text'))" :step="in_array($field, ['salary_min','salary_max']) ? '0.01' : null" :required="$field === 'company'" />
                        @endif
                    </div>
                @endforeach
                @if($section === 'details')
                    <div class="wide-field additional-fields"><h3>{{ __('jobs.extra_fields') }}</h3><p class="field-hint">{{ __('jobs.additional_hint') }}</p>
                        @foreach($extraFields as $index => $row)
                            <div class="extra-field-row" wire:key="extra-{{ $index }}"><flux:input wire:model="extraFields.{{ $index }}.key" :label="__('jobs.field_name')" /><flux:input wire:model="extraFields.{{ $index }}.value" :label="__('jobs.field_value')" /><flux:button type="button" wire:click="removeExtraField({{ $index }})" size="sm">{{ __('jobs.remove_field') }}</flux:button></div>
                        @endforeach
                        <flux:button type="button" wire:click="addExtraField" size="sm" icon="plus">{{ __('jobs.add_field') }}</flux:button>
                        <flux:error name="form.extra_fields" />
                    </div>
                @endif
            </div></section>
        @endforeach
        @if($errors->any())<div class="validation-summary" role="alert"><strong>{{ __('jobs.fix_errors') }}</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        <div class="form-actions"><a href="{{ $opportunityId ? '/opportunities/'.$opportunityId : '/' }}" wire:navigate class="back-link">{{ __('jobs.cancel') }}</a><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('jobs.save_job') }}</flux:button></div>
    </form>
</div>
