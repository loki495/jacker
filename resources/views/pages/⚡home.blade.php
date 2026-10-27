<?php

declare(strict_types=1);

use App\Actions\ChangeOpportunityStatus;
use App\Actions\QueueLinkChecks;
use App\Actions\RequestScoring;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'open_unapplied';

    #[Url]
    public string $archive = 'active';

    #[Url]
    public string $sort = 'rank';

    #[Url]
    public string $view = 'table';

    #[Url]
    public string $applied = '';

    #[Url]
    public bool $due = false;

    public array $selected = [];

    public string $notice = '';

    public function updated(string $property): void
    {
        if ($property !== 'page' && ! str_starts_with($property, 'selected')) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function togglePage(): void
    {
        $ids = $this->opportunities->pluck('id')->map(strval(...))->all();
        $this->selected = array_diff($ids, $this->selected) === [] ? [] : $ids;
    }

    public function closeSelected(): void
    {
        $jobs = $this->selectedJobs();
        DB::transaction(fn () => $jobs->each(fn (Opportunity $job) => app(ChangeOpportunityStatus::class)->handle($job, OpportunityStatus::Closed)));
        $this->notice = __('jobs.bulk_closed', ['count' => $jobs->count()]);
        $this->selected = [];
    }

    public function checkSelected(QueueLinkChecks $queue): void
    {
        $this->notice = __('jobs.bulk_checked', ['count' => $queue->forOpportunities($this->selectedJobs())]);
        $this->selected = [];
    }

    public function gradeSelected(RequestScoring $scoring): void
    {
        $queued = $this->selectedJobs()->filter(fn (Opportunity $job): bool => $scoring->forOpportunity($job))->count();
        $this->notice = __('jobs.bulk_graded', ['count' => $queued]);
        $this->selected = [];
    }

    private function selectedJobs()
    {
        return Opportunity::query()->whereIn('id', array_filter(array_map(intval(...), $this->selected)))->get();
    }

    public function changeStatus(int $id, string $status): void
    {
        $validated = validator(['status' => $status], ['status' => [\Illuminate\Validation\Rule::enum(OpportunityStatus::class)]])->validate();
        app(ChangeOpportunityStatus::class)->handle(Opportunity::query()->findOrFail($id), OpportunityStatus::from($validated['status']));
    }

    public function showMetric(string $key): void
    {
        abort_unless(in_array($key, ['total', 'screening', 'in_progress', 'offers', 'follow_ups'], true), 422);
        $this->reset('search', 'applied', 'due');
        $this->archive = 'active';
        $this->status = match ($key) {
            'in_progress' => 'in_progress',
            'screening' => 'recruiter',
            'offers' => OpportunityStatus::Offer->value,
            default => '',
        };
        $this->due = $key === 'follow_ups';
        $this->resetPage();
    }

    private function filtered(): Builder
    {
        return Opportunity::query()
            ->when($this->archive === 'active', fn ($q) => $q->whereNull('archived_at'))
            ->when($this->archive === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->when($this->search !== '', function ($q): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
                $q->where(fn ($q) => $q->whereRaw("company LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("title LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("location LIKE ? ESCAPE '\\'", [$term]));
            })
            ->when(OpportunityStatus::tryFrom($this->status), fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->status, ['open', 'open_unapplied'], true), fn ($q) => $q->where('status', '!=', OpportunityStatus::Closed->value))
            ->when($this->status === 'recruiter', fn ($q) => $q->whereIn('status', ['contacted', 'screening']))
            ->when($this->status === 'in_progress', fn ($q) => $q->whereIn('status', ['applied', 'screening', 'interviewing']))
            ->when($this->status === 'open_unapplied', fn ($q) => $q->where('has_applied', false))
            ->when(in_array($this->applied, ['yes', 'no'], true), fn ($q) => $q->where('has_applied', $this->applied === 'yes'))
            ->when($this->due, fn ($q) => $q->whereDate('follow_up_at', '<=', today())->whereNotIn('status', ['rejected', 'withdrawn', 'closed']));
    }

    #[Computed]
    public function opportunities()
    {
        $query = $this->filtered();
        match ($this->sort) {
            'company' => $query->orderBy('company'),
            'skill_match' => $query->orderByRaw('COALESCE(skill_match, 0) DESC'),
            'desirability' => $query->orderByRaw('COALESCE(desirability, 0) DESC'),
            'newest' => $query->latest('created_at'),
            'pay' => $query->orderByRaw(App\Support\PaySort::orderBy()),
            'location' => $query->orderByRaw(App\Support\LocationSort::usFirst()),
            'follow_up' => $query->orderByRaw('follow_up_at IS NULL')->orderBy('follow_up_at'),
            'deadline' => $query->orderByRaw('deadline IS NULL')->orderBy('deadline'),
            default => $query->bestFirst(),
        };

        return $query->orderByDesc('id')->paginate(15);
    }

    #[Computed]
    public function nextUp()
    {
        return Opportunity::query()->whereNull('archived_at')->where('has_applied', false)->whereIn('status', ['saved', 'preparing'])->bestFirst()->latest('id')->limit(3)->get();
    }

    #[Computed]
    public function metrics(): array
    {
        $active = Opportunity::query()->whereNull('archived_at');

        return [
            'total' => (clone $active)->count(),
            'screening' => (clone $active)->whereIn('status', ['contacted', 'screening'])->count(),
            'in_progress' => (clone $active)->whereIn('status', ['applied', 'screening', 'interviewing'])->count(),
            'offers' => (clone $active)->where('status', 'offer')->count(),
            'follow_ups' => (clone $active)->whereDate('follow_up_at', '<=', today())->whereNotIn('status', ['rejected', 'withdrawn', 'closed'])->count(),
        ];
    }
};
?>

<div>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.search_workspace') }}</div><h1>{{ __('jobs.next_move') }}</h1><p>{{ __('jobs.intro') }}</p></div><flux:button href="/opportunities/create" wire:navigate variant="primary" icon="plus">{{ __('jobs.add_job') }}</flux:button></div>
    <section class="metrics" aria-label="{{ __('jobs.summary') }}">
        @foreach($this->metrics as $key => $value)
            <button type="button" class="metric" wire:key="metric-{{ $key }}" wire:click="showMetric('{{ $key }}')" x-on:click="$nextTick(() => document.querySelector('.pipeline')?.scrollIntoView({behavior: 'smooth'}))" aria-pressed="{{ match ($key) { 'follow_ups' => $due, 'offers' => ! $due && $status === 'offer', 'in_progress' => ! $due && $status === 'in_progress', 'screening' => ! $due && $status === 'recruiter', default => ! $due && $status === '' && $applied === '' && $search === '' && $archive === 'active' } ? 'true' : 'false' }}"><span>{{ __('jobs.'.$key) }}</span><strong>{{ $value }}</strong><small>{{ __('jobs.metric_'.$key) }}</small></button>
        @endforeach
    </section>
    <section class="next-section"><div class="section-heading"><div><h2>{{ __('jobs.apply_next') }} <span class="small-tag">{{ __('jobs.your_priorities') }}</span></h2><p>{{ __('jobs.apply_next_hint') }}</p></div></div>
        <div class="next-grid">
            @forelse($this->nextUp as $job)
                <a href="/opportunities/{{ $job->id }}" wire:navigate class="next-card"><div class="next-card-top"><span class="company-avatar">{{ mb_substr($job->company, 0, 1) }}</span><span class="status-badge status-{{ $job->status->value }}">{{ $job->status->label() }}</span></div><h3>{{ $job->title ?: __('jobs.untitled_role') }}</h3><p>{{ $job->company }} @if($job->location)<span>· {{ $job->location }}</span>@endif</p><div class="card-ranks"><span>{{ __('jobs.want') }} <b class="score-pill score-{{ $job->desirability ?? 'none' }}">{{ $job->desirability ? $job->desirability.'/5' : '—' }}</b></span><span>{{ __('jobs.fit') }} <b class="score-pill score-{{ $job->skill_match ?? 'none' }}">{{ $job->skill_match ? $job->skill_match.'/5' : '—' }}</b></span><span class="card-arrow">↗</span></div></a>
            @empty
                <div class="empty-next"><strong>{{ __('jobs.next_empty_title') }}</strong><p>{{ __('jobs.next_empty') }}</p><a href="/opportunities/create" wire:navigate>{{ __('jobs.add_first') }} ↗</a></div>
            @endforelse
        </div>
    </section>
    <section class="pipeline panel">
        <div class="pipeline-header"><div><h2>{{ __('jobs.pipeline') }} <span class="count-badge">{{ $this->opportunities->total() }}</span></h2><p>{{ __('jobs.pipeline_hint') }}</p></div><div class="view-switch" aria-label="{{ __('jobs.view') }}"><button wire:click="$set('view', 'table')" @class(['active' => $view !== 'board'])>{{ __('jobs.table') }}</button><button wire:click="$set('view', 'board')" @class(['active' => $view === 'board'])>{{ __('jobs.board') }}</button></div></div>
        <div class="filters">
            <div class="search-control"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('jobs.search')" :aria-label="__('jobs.search')" /></div>
            @if($view !== 'board')<flux:select wire:model.live="status" :aria-label="__('jobs.status')"><option value="open_unapplied">{{ __('jobs.status_open_unapplied') }}</option><option value="open">{{ __('jobs.status_open') }}</option><option value="recruiter">{{ __('jobs.status_recruiter') }}</option><option value="in_progress">{{ __('jobs.status_in_progress') }}</option><option value="">{{ __('jobs.all_statuses') }}</option>@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>@endif
            <flux:select wire:model.live="applied" :aria-label="__('jobs.application_filter')"><option value="">{{ __('jobs.all_applications') }}</option><option value="no">{{ __('jobs.not_applied') }}</option><option value="yes">{{ __('jobs.has_applied') }}</option></flux:select>
            <flux:select wire:model.live="sort" :aria-label="__('jobs.sort')">@foreach(['rank','skill_match','desirability','newest','pay','location','company','follow_up','deadline'] as $option)<option value="{{ $option }}">{{ __('jobs.sort_'.$option) }}</option>@endforeach</flux:select>
        </div>
        <div class="secondary-filters"><flux:checkbox wire:model.live="due" :label="__('jobs.due_only')" /><flux:select wire:model.live="archive" :aria-label="__('jobs.archive_filter')"><option value="active">{{ __('jobs.active_records') }}</option><option value="archived">{{ __('jobs.archived_records') }}</option><option value="all">{{ __('jobs.all_records') }}</option></flux:select></div>
        @if($notice)<p role="status" class="rounded-xl bg-teal-50 p-3 text-teal-900">{{ $notice }}</p>@endif
        @if($selected)<div class="flex flex-wrap items-center gap-3 p-3"><span>{{ __('jobs.bulk_selected', ['count' => count($selected)]) }}</span><flux:button size="sm" wire:click="checkSelected">{{ __('jobs.bulk_check') }}</flux:button><flux:button size="sm" wire:click="gradeSelected">{{ __('jobs.bulk_grade') }}</flux:button><flux:button size="sm" wire:click="closeSelected" wire:confirm="{{ __('jobs.bulk_close_confirm', ['count' => count($selected)]) }}">{{ __('jobs.bulk_close') }}</flux:button></div>@endif
        @if($view === 'board')
            <div class="stage-filter px-6 pb-4"><flux:select wire:model.live="status" :label="__('jobs.stage')"><option value="open_unapplied">{{ __('jobs.status_open_unapplied') }}</option><option value="open">{{ __('jobs.status_open') }}</option><option value="recruiter">{{ __('jobs.status_recruiter') }}</option><option value="in_progress">{{ __('jobs.status_in_progress') }}</option><option value="">{{ __('jobs.all_statuses') }}</option>@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select></div>
            <div class="board">
                @forelse($this->opportunities as $job)
                    <article class="board-card" data-status="{{ $job->status->value }}" wire:key="board-{{ $job->id }}">
                            <header class="bc-head"><input type="checkbox" wire:model.live="selected" value="{{ $job->id }}" aria-label="{{ __('jobs.bulk_select', ['company' => $job->company]) }}"><a href="/opportunities/{{ $job->id }}" wire:navigate class="bc-company">{{ $job->company }}</a>@if($job->application_url)<a href="{{ $job->application_url }}" target="_blank" rel="noopener noreferrer" class="bc-link" title="{{ __('jobs.open_application') }}" aria-label="{{ __('jobs.open_application_for', ['company' => $job->company]) }}">↗</a>@endif</header>
                            <a href="/opportunities/{{ $job->id }}" wire:navigate class="bc-title">{{ $job->title ?: __('jobs.untitled_role') }}</a>
                            <dl class="bc-meta">
                                <div><dt>{{ __('jobs.location') }}</dt><dd>{{ $job->work_arrangement->label() }}@if($job->location) · {{ $job->location }}@endif</dd></div>
                                <div><dt>{{ __('jobs.compensation') }}</dt><dd>@if($job->salary_min !== null || $job->salary_max !== null){{ $job->currency }} {{ $job->salary_min !== null ? number_format((float) $job->salary_min, 0) : '—' }}–{{ $job->salary_max !== null ? number_format((float) $job->salary_max, 0) : '—' }} {{ $job->pay_period->label() }}@else<span class="muted">{{ __('jobs.not_listed') }}</span>@endif</dd></div>
                            </dl>
                            <div class="bc-ranks"><span>{{ __('jobs.want') }} <b class="rank-score score-{{ $job->desirability ?? 'none' }}">{{ $job->desirability ?? '—' }}</b></span><span>{{ __('jobs.fit') }} <b class="rank-score secondary score-{{ $job->skill_match ?? 'none' }}">{{ $job->skill_match ?? '—' }}</b></span></div>
                            <select aria-label="{{ __('jobs.change_status') }}" wire:change="changeStatus({{ $job->id }}, $event.target.value)">@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}" @selected($job->status === $option)>{{ $option->label() }}</option>@endforeach</select>
                        </article>
                @empty
                    <div class="table-empty"><h3>{{ __('jobs.pipeline_empty_title') }}</h3><p>{{ __('jobs.pipeline_empty') }}</p></div>
                @endforelse
            </div>
            <p class="board-note">{{ __('jobs.board_hint') }}</p>
        @else
            <div class="table-scroll"><table><thead><tr><th><input type="checkbox" wire:click="togglePage" @checked($this->opportunities->count() > 0 && collect($this->opportunities->pluck('id'))->every(fn ($id) => in_array((string) $id, $selected, true))) aria-label="{{ __('jobs.bulk_select_page') }}"></th><th>{{ __('jobs.role_company') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.location') }}</th><th>{{ __('jobs.compensation') }}</th><th>{{ __('jobs.want') }}</th><th>{{ __('jobs.fit') }}</th><th>{{ __('jobs.follow_up') }}</th></tr></thead><tbody>
                @forelse($this->opportunities as $job)
                    <tr wire:key="row-{{ $job->id }}"><td><input type="checkbox" wire:model.live="selected" value="{{ $job->id }}" aria-label="{{ __('jobs.bulk_select', ['company' => $job->company]) }}"></td><td><div class="flex items-baseline gap-3"><a class="role-link" href="/opportunities/{{ $job->id }}" wire:navigate>{{ $job->title ?: __('jobs.untitled_role') }}</a>@if($job->application_url)<a href="{{ $job->application_url }}" target="_blank" rel="noopener noreferrer" title="{{ __('jobs.open_application') }}" aria-label="{{ __('jobs.open_application_for', ['company' => $job->company]) }}">↗</a>@endif</div><span class="company-name company-lg">{{ $job->company }}@if($job->archived_at) · {{ __('jobs.archived') }}@endif</span></td><td><select class="status-select status-{{ $job->status->value }}" aria-label="{{ __('jobs.status_for', ['company' => $job->company]) }}" wire:change="changeStatus({{ $job->id }}, $event.target.value)">@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}" @selected($job->status === $option)>{{ $option->label() }}</option>@endforeach</select></td><td>{{ $job->location ?: '—' }}<span class="company-name">{{ $job->work_arrangement->label() }}</span></td><td class="salary">@if($job->salary_min !== null || $job->salary_max !== null){{ $job->currency }} {{ $job->salary_min !== null ? number_format((float)$job->salary_min, 0) : '—' }}–{{ $job->salary_max !== null ? number_format((float)$job->salary_max, 0) : '—' }}<span class="company-name">{{ $job->pay_period->label() }}</span>@else<span class="muted">{{ __('jobs.not_listed') }}</span>@endif</td><td><span class="rank-score score-{{ $job->desirability ?? 'none' }}">{{ $job->desirability ?? '—' }}</span></td><td><span class="rank-score secondary score-{{ $job->skill_match ?? 'none' }}">{{ $job->skill_match ?? '—' }}</span></td><td>{{ $job->follow_up_at?->format('M j, Y') ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="8"><div class="table-empty"><h3>{{ __('jobs.pipeline_empty_title') }}</h3><p>{{ __('jobs.pipeline_empty') }}</p></div></td></tr>
                @endforelse
            </tbody></table></div>
        @endif
        <div class="pagination">{{ $this->opportunities->links() }}</div>
    </section>
</div>
