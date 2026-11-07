<?php

declare(strict_types=1);

use App\Actions\ArchiveOpportunity;
use App\Actions\ChangeOpportunityStatus;
use App\Actions\FindRelatedContent;
use App\Actions\MarkMaterialsUsed;
use App\Actions\QueueLinkChecks;
use App\Actions\RequestAgentApply;
use App\Actions\RequestLetterSuggestions;
use App\Actions\RequestScoring;
use App\Actions\SaveCoverLetter;
use App\Actions\SaveResume;
use App\Enums\EmbeddingKind;
use App\Enums\OpportunityStatus;
use App\Models\AiRequest;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Services\MarkdownPdf;
use App\Support\AgentFiles;
use App\Support\EmbeddingSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
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

    public string $resumeBody = '';

    #[Url(as: 'materials')]
    public string $tab = 'resume';

    public string $resumeVersion = 'none';

    public string $letterVersion = 'none';

    public function mount(Opportunity $opportunity): void
    {
        $this->opportunityId = $opportunity->id;
        $this->status = $opportunity->status->value;
        $this->letterBody = (string) $this->letter?->body;
        $this->resumeBody = (string) $this->resume?->body;
        $this->tab = $this->tab === 'letter' ? 'letter' : 'resume';
        $this->resumeVersion = $this->versions('resume')[0] ?? 'none';
        $this->letterVersion = $this->versions('letter')[0] ?? 'none';
    }

    #[Computed]
    public function resume(): ?Resume
    {
        return Resume::query()->where('opportunity_id', $this->opportunityId)->first();
    }

    /** @return list<string> versions that exist for the kind, in preference order: edited, generated, general */
    public function versions(string $kind): array
    {
        $found = [];
        if ($kind === 'resume' ? $this->resume instanceof Resume : $this->letter instanceof CoverLetter) {
            $found[] = 'edited';
        }
        if (in_array($kind, AgentFiles::available($this->opportunity), true)) {
            $found[] = 'generated';
        }
        if ($kind === 'resume' && AgentFiles::generalResume() !== null) {
            $found[] = 'general';
        }

        return $found;
    }

    public function markUsed(MarkMaterialsUsed $mark): void
    {
        $this->resetErrorBag('used');
        $mark->handle($this->opportunity, $this->resumeVersion, $this->letterVersion);
        unset($this->opportunity);
        session()->flash('message', __('materials.used_done'));
    }

    private function flashSaved(string $kind, string $saved): void
    {
        try {
            (new MarkdownPdf)->render($kind === 'resume' ? $this->resumeBody : $this->letterBody, $kind);
        } catch (\InvalidArgumentException $e) {
            $saved = __('materials.render_problem', ['message' => $e->getMessage()]);
        }
        session()->flash('message', $saved);
    }

    public function saveResume(SaveResume $save): void
    {
        $this->validate(['resumeBody' => ['nullable', 'string', 'max:'.SaveResume::MAX]], ['resumeBody.max' => __('materials.resume_too_long', ['max' => SaveResume::MAX])]);
        $saved = $save->handle($this->opportunity, $this->resumeBody);
        unset($this->resume);
        $this->resumeBody = (string) $saved?->body;
        $this->resumeVersion = $saved instanceof Resume ? 'edited' : ($this->versions('resume')[0] ?? 'none');
        $this->flashSaved('resume', $saved instanceof Resume ? __('materials.resume_saved') : __('materials.resume_removed'));
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
        $this->letterVersion = $saved instanceof CoverLetter ? 'edited' : ($this->versions('letter')[0] ?? 'none');
        $this->flashSaved('letter', $saved instanceof CoverLetter ? __('letter.saved') : __('letter.removed'));
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

    public function generateApplication(RequestAgentApply $apply): void
    {
        session()->flash('message', $apply->handle($this->opportunity) instanceof AiRequest ? __('jobs.apply_queued') : __('jobs.apply_unavailable'));
    }

    public function confirmApplyLink(RequestAgentApply $apply): void
    {
        $apply->confirmLink($this->opportunity);
        $this->opportunity->refresh();
    }

    #[Computed]
    public function applyPending(): bool
    {
        return app(RequestAgentApply::class)->pending($this->opportunity);
    }

    #[Computed]
    public function applyRun(): ?AiRequest
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_APPLY)->where('opportunity_id', $this->opportunity->id)->latest('id')->first();
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
        <section class="panel detail-panel materials" id="materials" @if($this->letterPending || $this->applyPending) wire:poll.5s @endif><h2>{{ __('materials.heading') }}</h2><p class="field-hint">{{ __('materials.intro') }}</p>
            <div class="badges" role="tablist">@foreach(['resume' => 'tab_resume', 'letter' => 'tab_letter'] as $key => $label)<flux:button role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" :variant="$tab === $key ? 'primary' : 'ghost'">{{ __('materials.'.$label) }}</flux:button>@endforeach</div>
            @foreach(['resume' => 'resumeBody', 'letter' => 'letterBody'] as $kind => $model)
            @if($tab === $kind)
            @php($versionProp = $kind.'Version')
            @php($options = $this->versions($kind))
            <div role="tabpanel" id="materials-{{ $kind }}">
                <flux:select wire:model.live="{{ $versionProp }}" :label="__('materials.version')">
                    @foreach($options as $option)<option value="{{ $option }}">{{ __('materials.version_'.$option) }}</option>@endforeach
                    @if($kind === 'letter')<option value="none">{{ __('materials.version_none') }}</option>@endif
                    @if($options === [] && $kind === 'resume')<option value="none">{{ __('materials.unavailable') }}</option>@endif
                </flux:select>
                @if($this->{$versionProp} === 'none')<p class="field-hint">{{ $kind === 'letter' ? __('materials.none_note') : __('materials.resume_none_note') }}</p>
                @else<div class="badges"><flux:button href="{{ route('opportunities.material', [$job, $kind, $this->{$versionProp}]) }}" target="_blank" icon="eye">{{ __('materials.preview') }}</flux:button><flux:button href="{{ route('opportunities.material', [$job, $kind, $this->{$versionProp}, 'download' => 1]) }}" icon="arrow-down-tray">{{ __('materials.download') }}</flux:button></div>@endif
                @if(! in_array('generated', $options, true))<p class="field-hint">{{ __('materials.generated_missing') }}</p>@endif
                <flux:textarea wire:model="{{ $model }}" rows="14" :label="$kind === 'resume' ? __('materials.resume_body') : __('letter.body')" />@error($model)<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
                <details><summary>{{ __('materials.help_title') }}</summary><p class="field-hint">{{ __('materials.help') }}</p></details>
                @if($kind === 'letter')<input type="file" wire:model="letterFile" accept=".txt,.md,text/plain,text/markdown" aria-label="{{ __('letter.upload') }}">@error('letterFile')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror @endif
                <div class="badges"><flux:button wire:click="{{ $kind === 'resume' ? 'saveResume' : 'saveLetter' }}" variant="primary" wire:loading.attr="disabled">{{ $kind === 'resume' ? __('materials.save_resume') : __('letter.save') }}</flux:button>
                @if($kind === 'letter' && $this->letter)<flux:button wire:click="suggestLetter" wire:loading.attr="disabled" :disabled="$this->letterPending">{{ $this->letterPending ? __('letter.suggest_working') : __('letter.suggest') }}</flux:button>@endif</div>
                @if($kind === 'letter' && $this->letter?->suggestions)<h3>{{ __('letter.suggestions') }}</h3><p class="field-hint">{{ __('letter.suggestions_note', ['when' => $this->letter->suggested_at?->diffForHumans()]) }}</p><p class="preserve-text">{{ $this->letter->suggestions }}</p>@endif
            </div>
            @endif
            @endforeach
            <h3>{{ __('materials.used_heading') }}</h3><p class="field-hint">{{ __('materials.used_hint') }}</p>
            @if($job->materials_used_at)<p>{{ __('materials.used_record', ['when' => $job->materials_used_at->format('M j, Y g:i a'), 'resume' => $job->resume_choice ?? __('materials.used_none'), 'letter' => $job->letter_choice ?? __('materials.used_none')]) }}</p><div class="badges">@if($job->resume_choice)<flux:button href="{{ route('opportunities.used', [$job, 'resume']) }}" target="_blank" icon="eye">{{ __('materials.used_open_resume') }}</flux:button>@endif @if($job->letter_choice)<flux:button href="{{ route('opportunities.used', [$job, 'letter']) }}" target="_blank" icon="eye">{{ __('materials.used_open_letter') }}</flux:button>@endif</div>@endif
            @error('used')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            <flux:button wire:click="markUsed" wire:confirm="{{ __('materials.used_hint') }}" wire:loading.attr="disabled">{{ __('materials.used_button') }}</flux:button>
            @if(App\Support\AgentWorker::online() || $this->applyRun)
                <h3>{{ __('materials.run_title') }}</h3><p class="field-hint">{{ __('jobs.apply_hint') }}</p>
                @if($this->applyRun)<p><span class="status-badge link-{{ $this->applyRun->status->value }}">{{ $this->applyRun->status->label() }}</span> {{ $this->applyRun->message }}</p>@endif
                @if(app(App\Actions\RequestAgentApply::class)->needsLinkConfirmation($job))<p role="alert">{{ __('jobs.apply_link_confirm', ['url' => $job->application_url]) }}</p><button type="button" wire:click="confirmApplyLink" class="archive-button">{{ __('jobs.apply_link_confirm_button') }}</button>@elseif(App\Support\AgentWorker::online() && app(App\Actions\RequestAgentApply::class)->eligible($job))<button type="button" wire:click="generateApplication" class="archive-button" wire:loading.attr="disabled" @disabled($this->applyPending)>{{ $this->applyPending ? __('jobs.apply_working') : __('jobs.apply_run') }}</button>@endif
            @endif
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
                            <li><a class="role-link" href="/opportunities/{{ $match['opportunity']->id }}{{ $property === 'relatedLetters' ? '?materials=letter#materials' : '' }}" wire:navigate>{{ $match['opportunity']->company }} — {{ $match['opportunity']->title }}</a></li>
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
