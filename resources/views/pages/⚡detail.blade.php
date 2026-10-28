<?php

declare(strict_types=1);

use App\Actions\ArchiveOpportunity;
use App\Actions\ChangeOpportunityStatus;
use App\Actions\FindRelatedContent;
use App\Actions\QueueLinkChecks;
use App\Actions\RequestLetterSuggestions;
use App\Actions\RequestScoring;
use App\Actions\SaveCoverLetter;
use App\Enums\EmbeddingKind;
use App\Enums\OpportunityStatus;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Support\EmbeddingSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $opportunityId;

    public string $status = '';

    public string $letterBody = '';

    public $letterFile = null;

    public function mount(Opportunity $opportunity): void
    {
        $this->opportunityId = $opportunity->id;
        $this->status = $opportunity->status->value;
        $this->letterBody = (string) $this->letter?->body;
    }

    #[Computed]
    public function letter(): ?CoverLetter
    {
        return CoverLetter::query()->where('opportunity_id', $this->opportunityId)->first();
    }

    #[Computed]
    public function letterPending(): bool
    {
        return $this->letter instanceof CoverLetter && app(RequestLetterSuggestions::class)->isPending($this->letter);
    }

    public function updatedLetterFile(): void
    {
        $this->validate(['letterFile' => ['file', 'mimes:txt,md', 'max:200']], ['letterFile.*' => __('letter.upload_invalid')]);
        $this->letterBody = (string) mb_convert_encoding((string) file_get_contents($this->letterFile->getRealPath()), 'UTF-8', 'UTF-8');
        $this->letterFile = null;
    }

    public function saveLetter(SaveCoverLetter $save): void
    {
        $this->validate(['letterBody' => ['nullable', 'string', 'max:'.SaveCoverLetter::MAX]], ['letterBody.max' => __('letter.body_too_long', ['max' => SaveCoverLetter::MAX])]);
        $saved = $save->handle($this->opportunity, $this->letterBody);
        unset($this->letter);
        $this->letterBody = (string) $saved?->body;
        session()->flash('message', $saved instanceof CoverLetter ? __('letter.saved') : __('letter.removed'));
    }

    public function suggestLetter(RequestLetterSuggestions $suggest): void
    {
        $letter = $this->letter;
        session()->flash('message', match (true) {
            ! $letter instanceof CoverLetter => __('letter.suggest_save_first'),
            $suggest->handle($letter) => __('letter.suggest_queued'),
            default => __('letter.suggest_pending'),
        });
    }

    #[Computed]
    public function opportunity(): Opportunity
    {
        return Opportunity::query()->with('events')->findOrFail($this->opportunityId);
    }

    public function updatedStatus(): void
    {
        $this->validate(['status' => [\Illuminate\Validation\Rule::enum(OpportunityStatus::class)]]);
        app(ChangeOpportunityStatus::class)->handle($this->opportunity, OpportunityStatus::from($this->status));
        unset($this->opportunity);
    }

    public function checkLink(QueueLinkChecks $queue): void
    {
        $queued = $queue->forOpportunities(collect([$this->opportunity]));
        session()->flash('message', $queued > 0 ? __('jobs.link_queued_count', ['count' => $queued]) : __('jobs.link_nothing_queued'));
    }

    public function scoreWithAi(RequestScoring $scoring): void
    {
        session()->flash('message', $scoring->forOpportunity($this->opportunity) ? __('jobs.score_one_queued') : __('jobs.score_one_pending'));
    }

    #[Computed]
    public function scorePending(): bool
    {
        return app(RequestScoring::class)->isPending($this->opportunity);
    }

    /** @return list<array{opportunity: Opportunity, similarity: float}> */
    #[Computed]
    public function relatedJobs(): array
    {
        return app(FindRelatedContent::class)->handle($this->opportunity, EmbeddingKind::Job);
    }

    /** @return list<array{opportunity: Opportunity, similarity: float}> */
    #[Computed]
    public function relatedLetters(): array
    {
        return app(FindRelatedContent::class)->handle($this->opportunity, EmbeddingKind::Letter);
    }

    public function archive(): void
    {
        app(ArchiveOpportunity::class)->handle($this->opportunity, ! $this->opportunity->archived_at);
        unset($this->opportunity);
    }
};
?>

<div>
    @php($job = $this->opportunity)
    <a href="/" wire:navigate class="back-link">← {{ __('jobs.back_pipeline') }}</a>
    @if(session('message'))<div class="success-message" role="status">{{ session('message') }}</div>@endif
    <div class="page-heading detail-heading"><div><div class="eyebrow">{{ $job->company }}</div><h1>{{ $job->title ?: __('jobs.untitled_role') }}</h1><p>{{ $job->location ?: __('jobs.location_unknown') }}</p>@if($job->application_url && in_array(strtolower(parse_url($job->application_url, PHP_URL_SCHEME) ?: ''), ['http','https'], true))<a class="external-link" href="{{ $job->application_url }}" target="_blank" rel="noopener noreferrer">{{ __('jobs.open_application_url') }} ↗</a>@endif<div class="badges"><span class="status-badge status-{{ $job->status->value }}">{{ $job->status->label() }}</span><span class="status-badge">{{ $job->work_arrangement->label() }}</span><span class="status-badge">{{ $job->employment_type->label() }}</span></div></div><div>@unless($job->archived_at)<flux:button href="/import?job={{ $job->id }}#saved" wire:navigate icon="clipboard-document">{{ __('jobs.add_from_text') }}</flux:button> @endunless<flux:button href="/opportunities/{{ $job->id }}/questions" wire:navigate icon="chat-bubble-left-right">{{ __('questions.answers_tab') }}</flux:button> <flux:button href="/opportunities/{{ $job->id }}/edit" wire:navigate icon="pencil-square">{{ __('jobs.edit_job') }}</flux:button></div></div>
    @if($job->archived_at)<div class="archive-banner">{{ __('jobs.archive_banner') }}</div>@endif
    <div class="detail-grid"><div class="detail-main">
        <section class="panel detail-panel"><h2>{{ __('jobs.at_a_glance') }}</h2><dl class="detail-facts">
            <div><dt>{{ __('jobs.pay') }}</dt><dd>{{ $job->payRange() ?? '—' }}</dd></div>
            @foreach(['salary_notes','hours','remote_restrictions','source','contact'] as $field)<div><dt>{{ __('jobs.'.$field) }}</dt><dd>{{ $job->{$field} ?: '—' }}</dd></div>@endforeach
        </dl></section>
        @foreach(['benefits','responsibilities','requirements'] as $field)<section class="panel detail-panel"><h2>{{ __('jobs.'.$field) }}</h2>@if($job->listItems($field))<ul class="detail-list">@foreach($job->listItems($field) as $item)<li>{{ $item }}</li>@endforeach</ul>@else<p class="preserve-text">{{ __('jobs.nothing_added') }}</p>@endif</section>@endforeach
        <section class="panel detail-panel"><h2>{{ __('jobs.notes') }}</h2><p class="preserve-text">{{ $job->notes ?: __('jobs.nothing_added') }}</p></section>
        <section class="panel detail-panel" id="letter" @if($this->letterPending) wire:poll.5s @endif><h2>{{ __('letter.heading') }}</h2><p class="field-hint">{{ __('letter.intro') }}</p>
            <flux:textarea wire:model="letterBody" rows="14" :label="__('letter.body')" />@error('letterBody')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            <input type="file" wire:model="letterFile" accept=".txt,.md,text/plain,text/markdown" aria-label="{{ __('letter.upload') }}">@error('letterFile')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            <div class="badges"><flux:button wire:click="saveLetter" variant="primary" wire:loading.attr="disabled">{{ __('letter.save') }}</flux:button>
            @if($this->letter)<flux:button href="{{ route('opportunities.cover-letter', $job) }}" icon="arrow-down-tray">{{ __('letter.pdf') }}</flux:button><flux:button wire:click="suggestLetter" wire:loading.attr="disabled" :disabled="$this->letterPending">{{ $this->letterPending ? __('letter.suggest_working') : __('letter.suggest') }}</flux:button>@endif</div>
            @if($this->letter?->suggestions)<h3>{{ __('letter.suggestions') }}</h3><p class="field-hint">{{ __('letter.suggestions_note', ['when' => $this->letter->suggested_at?->diffForHumans()]) }}</p><p class="preserve-text">{{ $this->letter->suggestions }}</p>@endif
        </section>
        @if($job->extra_fields)<section class="panel detail-panel"><h2>{{ __('jobs.extra_fields') }}</h2><dl class="detail-facts">@foreach($job->extra_fields as $key => $value)<div><dt>{{ ucfirst(str_replace('_', ' ', $key)) }}</dt><dd>{{ $value ?? '—' }}</dd></div>@endforeach</dl></section>@endif
    </div><aside class="detail-sidebar">
        <section class="panel detail-panel"><h2>{{ __('jobs.application') }}</h2><flux:select wire:model.live="status" :label="__('jobs.status')">@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select><dl class="detail-facts single"><div><dt>{{ __('jobs.has_applied') }}</dt><dd>{{ $job->has_applied ? __('jobs.yes') : __('jobs.no') }}</dd></div>@foreach(['applied_at','deadline','follow_up_at'] as $field)<div><dt>{{ __('jobs.'.$field) }}</dt><dd>{{ $job->{$field}?->format('M j, Y') ?? __('jobs.unknown') }}</dd></div>@endforeach</dl>
        @foreach(['application_url','company_url'] as $field)@if($job->{$field} && in_array(strtolower(parse_url($job->{$field}, PHP_URL_SCHEME) ?: ''), ['http','https'], true))<a class="external-link" href="{{ $job->{$field} }}" target="_blank" rel="noopener noreferrer">{{ __('jobs.open_'.$field) }} ↗</a>@endif@endforeach
        @if($job->application_url)<p class="field-hint">@if($job->link_checked_at)<span class="status-badge link-{{ $job->link_status === 'ok' ? 'done' : 'failed' }}">{{ App\Enums\LinkResult::tryFrom((string) $job->link_status)?->label() }}</span> {{ __('jobs.link_last_checked', ['when' => $job->link_checked_at->diffForHumans()]) }}@else{{ __('jobs.link_never_checked') }}@endif</p><button type="button" wire:click="checkLink" class="archive-button" wire:loading.attr="disabled">{{ __('jobs.link_check_one') }}</button>@endif
        </section>
        <section class="panel detail-panel" @if($this->scorePending) wire:poll.5s @endif><h2>{{ __('jobs.rankings') }}</h2>@foreach(['desirability','skill_match'] as $field)<div class="detail-ranking"><span>{{ __('jobs.'.$field) }}</span><strong class="score-pill score-{{ $job->{$field} ?? 'none' }}">{{ $job->{$field} ? $job->{$field}.' / 5' : __('jobs.unscored') }}</strong></div><p class="preserve-text muted">{{ $job->{$field.'_notes'} }}</p>@endforeach<button type="button" wire:click="scoreWithAi" class="archive-button" wire:loading.attr="disabled" @disabled($this->scorePending)>{{ $this->scorePending ? __('jobs.score_one_working') : __('jobs.score_one') }}</button></section>
        <section class="panel detail-panel"><h2>{{ __('jobs.status_history') }}</h2><ol class="history">@forelse($job->events as $event)<li><strong>{{ $event->to_status->label() }}</strong><span>{{ $event->created_at->format('M j, Y · g:i a') }}</span></li>@empty<li>{{ __('jobs.no_history') }}</li>@endforelse</ol></section>
        @if(EmbeddingSettings::enabled())
            @foreach(['relatedJobs' => 'related_jobs', 'relatedLetters' => 'related_letters'] as $property => $label)
                <section class="panel detail-panel">
                    <h2>{{ __('settings.'.$label) }}</h2>
                    <ul class="detail-list">
                        @forelse($this->{$property} as $match)
                            <li><a class="role-link" href="/opportunities/{{ $match['opportunity']->id }}{{ $property === 'relatedLetters' ? '#letter' : '' }}" wire:navigate>{{ $match['opportunity']->company }} — {{ $match['opportunity']->title }}</a></li>
                        @empty
                            <li>{{ __('settings.related_empty') }}</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        @endif
        <button type="button" wire:click="archive" class="archive-button" wire:loading.attr="disabled">{{ $job->archived_at ? __('jobs.restore_job') : __('jobs.archive_job') }}</button>
    </aside></div>
</div>
