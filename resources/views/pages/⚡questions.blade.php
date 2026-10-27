<?php

declare(strict_types=1);

use App\Actions\MoveInterviewQuestion;
use App\Actions\SaveInterviewQuestion;
use App\Models\InterviewQuestion;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $stage = '';

    public ?int $editingId = null;

    public array $form = ['stage' => 'screening', 'question' => '', 'options' => ''];

    public function edit(int $id): void
    {
        $question = InterviewQuestion::query()->findOrFail($id);
        $this->editingId = $id;
        $this->form = ['stage' => $question->stage->value, 'question' => $question->question, 'options' => implode("\n", $question->options ?? [])];
    }

    public function cancel(): void
    {
        $this->reset('editingId');
        $this->form = ['stage' => $this->stage ?: 'screening', 'question' => '', 'options' => ''];
        $this->resetValidation();
    }

    public function save(SaveInterviewQuestion $save): void
    {
        try {
            $save->handle($this->form, $this->editingId ? InterviewQuestion::query()->findOrFail($this->editingId) : null);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['form.'.$key => $messages])->all());
        }
        session()->flash('message', __('questions.saved'));
        $this->form = ['stage' => $this->form['stage'], 'question' => '', 'options' => ''];
        $this->reset('editingId');
        unset($this->questions);
    }

    public function delete(int $id): void
    {
        InterviewQuestion::query()->whereKey($id)->delete();
        if ($this->editingId === $id) {
            $this->cancel();
        }
        unset($this->questions);
    }

    public function move(int $id, int $direction, MoveInterviewQuestion $move): void
    {
        $move->handle(InterviewQuestion::query()->findOrFail($id), $direction);
        unset($this->questions);
    }

    #[Computed]
    public function questions()
    {
        return InterviewQuestion::query()->when($this->stage !== '', fn ($query) => $query->where('stage', $this->stage))->orderBy('stage')->orderBy('position')->orderBy('id')->get()->groupBy(fn (InterviewQuestion $question): string => $question->stage->value);
    }
};
?>

<div>
    <a href="/" wire:navigate class="back-link">← {{ __('jobs.back_pipeline') }}</a>
    @if(session('message'))<div class="success-message" role="status">{{ session('message') }}</div>@endif
    <div class="page-heading"><div><div class="eyebrow">{{ __('questions.nav') }}</div><h1>{{ __('questions.title') }}</h1><p>{{ __('questions.intro') }}</p></div></div>
    <section class="panel detail-panel">
        <form wire:submit="save" class="editor-form">
            <div class="form-grid">
                <flux:select wire:model="form.stage" :label="__('questions.stage')">@foreach(App\Models\InterviewQuestion::stages() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>
                <div class="wide-field"><flux:textarea wire:model="form.question" :label="__('questions.question')" rows="2" /></div>
                <div class="wide-field"><flux:textarea wire:model="form.options" :label="__('questions.options')" rows="3" /><p class="field-hint">{{ __('questions.options_hint') }}</p></div>
            </div>
            @if($errors->any())<div class="validation-summary" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
            <div class="form-actions">@if($editingId)<flux:button type="button" wire:click="cancel">{{ __('questions.cancel') }}</flux:button>@endif<flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $editingId ? __('questions.update') : __('questions.add') }}</flux:button></div>
        </form>
    </section>
    <flux:select wire:model.live="stage" :label="__('questions.stage')"><option value="">{{ __('questions.all_stages') }}</option>@foreach(App\Models\InterviewQuestion::stages() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>
    @foreach(App\Models\InterviewQuestion::stages() as $option)
        @continue($stage !== '' && $stage !== $option->value)
        <section class="panel detail-panel" wire:key="stage-{{ $option->value }}"><h2>{{ $option->label() }}</h2>
            @forelse($this->questions[$option->value] ?? [] as $question)
                <div class="detail-ranking" wire:key="q-{{ $question->id }}"><span>{{ $question->question }}@if($question->options)<small class="field-hint"> · {{ implode(' / ', $question->options) }}</small>@endif</span>
                    <span><flux:button size="sm" type="button" wire:click="move({{ $question->id }}, -1)" :aria-label="__('questions.move_up')" icon="arrow-up" /><flux:button size="sm" type="button" wire:click="move({{ $question->id }}, 1)" :aria-label="__('questions.move_down')" icon="arrow-down" /><flux:button size="sm" type="button" wire:click="edit({{ $question->id }})">{{ __('questions.edit') }}</flux:button><flux:button size="sm" type="button" wire:click="delete({{ $question->id }})" wire:confirm="{{ __('questions.delete_confirm') }}">{{ __('questions.delete') }}</flux:button></span></div>
            @empty
                <p class="preserve-text">{{ __('questions.empty') }}</p>
            @endforelse
        </section>
    @endforeach
</div>
