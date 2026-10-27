<?php

declare(strict_types=1);

use App\Actions\SaveQuestionAnswer;
use App\Enums\OpportunityStatus;
use App\Models\InterviewQuestion;
use App\Models\Opportunity;
use App\Models\QuestionAnswer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $opportunityId;

    #[Url]
    public string $stage = '';

    /** @var array<int, string> */
    public array $answers = [];

    public function mount(Opportunity $opportunity): void
    {
        $this->opportunityId = $opportunity->id;
        $stage = $opportunity->status === OpportunityStatus::Contacted ? OpportunityStatus::Screening : $opportunity->status;
        if ($this->stage === '' && in_array($stage, InterviewQuestion::stages(), true)) {
            $this->stage = $stage->value;
        }
        $this->answers = QuestionAnswer::query()->where('opportunity_id', $opportunity->id)->pluck('answer', 'interview_question_id')->all();
    }

    #[Computed]
    public function opportunity(): Opportunity
    {
        return Opportunity::query()->findOrFail($this->opportunityId);
    }

    public function updatedAnswers(?string $value, string $key, SaveQuestionAnswer $save): void
    {
        $save->handle($this->opportunity, InterviewQuestion::query()->findOrFail((int) $key), $value);
    }

    #[Computed]
    public function questions()
    {
        return InterviewQuestion::query()->when($this->stage !== '', fn ($query) => $query->where('stage', $this->stage))->orderBy('stage')->orderBy('position')->orderBy('id')->get()->groupBy(fn (InterviewQuestion $question): string => $question->stage->value);
    }
};
?>

<div>
    @php($job = $this->opportunity)
    <a href="/opportunities/{{ $job->id }}" wire:navigate class="back-link">← {{ $job->company }}</a>
    <div class="page-heading"><div><div class="eyebrow">{{ $job->company }}</div><h1>{{ __('questions.title') }}</h1><p>{{ __('questions.answers_intro') }}</p></div><flux:button href="/questions" wire:navigate icon="pencil-square">{{ __('questions.manage') }}</flux:button></div>
    <flux:select wire:model.live="stage" :label="__('questions.stage')"><option value="">{{ __('questions.all_stages') }}</option>@foreach(App\Models\InterviewQuestion::stages() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>
    @foreach(App\Models\InterviewQuestion::stages() as $option)
        @continue(! isset($this->questions[$option->value]))
        @php($group = $this->questions[$option->value])
        <section class="panel detail-panel" wire:key="stage-{{ $option->value }}"><h2>{{ $option->label() }} <small class="field-hint">{{ __('questions.answered', ['answered' => $group->filter(fn ($question) => ($answers[$question->id] ?? '') !== '')->count(), 'total' => $group->count()]) }}</small></h2>
            @foreach($group as $question)
                <div wire:key="q-{{ $question->id }}">
                    @if($question->options)
                        <flux:select wire:model.live="answers.{{ $question->id }}" :label="$question->question"><option value="">{{ __('questions.not_answered') }}</option>@foreach($question->options as $choice)<option value="{{ $choice }}">{{ $choice }}</option>@endforeach @if(($answers[$question->id] ?? '') !== '' && ! in_array($answers[$question->id], $question->options, true))<option value="{{ $answers[$question->id] }}">{{ __('questions.unlisted_answer', ['answer' => $answers[$question->id]]) }}</option>@endif</flux:select>
                    @else
                        <flux:textarea wire:model.blur="answers.{{ $question->id }}" :label="$question->question" rows="2" />
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach
</div>
