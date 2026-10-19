<?php

declare(strict_types=1);

use App\Actions\ChangeOpportunityStatus;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
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
    public string $status = '';

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

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function changeStatus(int $id, string $status): void
    {
        $validated = validator(['status' => $status], ['status' => [\Illuminate\Validation\Rule::enum(OpportunityStatus::class)]])->validate();
        app(ChangeOpportunityStatus::class)->handle(Opportunity::query()->findOrFail($id), OpportunityStatus::from($validated['status']));
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
            'follow_up' => $query->orderByRaw('follow_up_at IS NULL')->orderBy('follow_up_at'),
            'deadline' => $query->orderByRaw('deadline IS NULL')->orderBy('deadline'),
            default => $query->orderByRaw('COALESCE(desirability, 0) DESC')->orderByRaw('COALESCE(skill_match, 0) DESC'),
        };

        return $query->orderByDesc('id')->paginate(15);
    }

    #[Computed]
    public function nextUp()
    {
        return Opportunity::query()->whereNull('archived_at')->where('has_applied', false)->whereIn('status', ['saved', 'preparing'])->orderByRaw('COALESCE(desirability, 0) DESC')->orderByRaw('COALESCE(skill_match, 0) DESC')->latest('id')->limit(3)->get();
    }

    #[Computed]
    public function metrics(): array
    {
        $active = Opportunity::query()->whereNull('archived_at');

        return [
            'total' => (clone $active)->count(),
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
            <div class="metric"><span>{{ __('jobs.'.$key) }}</span><strong>{{ $value }}</strong><small>{{ __('jobs.metric_'.$key) }}</small></div>
        @endforeach
    </section>
    <section class="next-section"><div class="section-heading"><div><h2>{{ __('jobs.apply_next') }} <span class="small-tag">{{ __('jobs.your_priorities') }}</span></h2><p>{{ __('jobs.apply_next_hint') }}</p></div></div>
        <div class="next-grid">
            @forelse($this->nextUp as $job)
                <a href="/opportunities/{{ $job->id }}" wire:navigate class="next-card"><div class="next-card-top"><span class="company-avatar">{{ mb_substr($job->company, 0, 1) }}</span><span class="status-badge status-{{ $job->status->value }}">{{ $job->status->label() }}</span></div><h3>{{ $job->title ?: __('jobs.untitled_role') }}</h3><p>{{ $job->company }} @if($job->location)<span>· {{ $job->location }}</span>@endif</p><div class="card-ranks"><span>{{ __('jobs.want') }} <b>{{ $job->desirability ? $job->desirability.'/5' : '—' }}</b></span><span>{{ __('jobs.fit') }} <b>{{ $job->skill_match ? $job->skill_match.'/5' : '—' }}</b></span><span class="card-arrow">↗</span></div></a>
            @empty
                <div class="empty-next"><strong>{{ __('jobs.next_empty_title') }}</strong><p>{{ __('jobs.next_empty') }}</p><a href="/opportunities/create" wire:navigate>{{ __('jobs.add_first') }} ↗</a></div>
            @endforelse
        </div>
    </section>
    <section class="pipeline panel">
        <div class="pipeline-header"><div><h2>{{ __('jobs.pipeline') }} <span class="count-badge">{{ $this->opportunities->total() }}</span></h2><p>{{ __('jobs.pipeline_hint') }}</p></div><div class="view-switch" aria-label="{{ __('jobs.view') }}"><button wire:click="$set('view', 'table')" @class(['active' => $view !== 'board'])>{{ __('jobs.table') }}</button><button wire:click="$set('view', 'board')" @class(['active' => $view === 'board'])>{{ __('jobs.board') }}</button></div></div>
        <div class="filters">
            <div class="search-control"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('jobs.search')" :aria-label="__('jobs.search')" /></div>
            <flux:select wire:model.live="status" :aria-label="__('jobs.status')"><option value="">{{ __('jobs.all_statuses') }}</option>@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="applied" :aria-label="__('jobs.application_filter')"><option value="">{{ __('jobs.all_applications') }}</option><option value="no">{{ __('jobs.not_applied') }}</option><option value="yes">{{ __('jobs.has_applied') }}</option></flux:select>
            <flux:select wire:model.live="sort" :aria-label="__('jobs.sort')">@foreach(['rank','skill_match','desirability','newest','company','follow_up','deadline'] as $option)<option value="{{ $option }}">{{ __('jobs.sort_'.$option) }}</option>@endforeach</flux:select>
        </div>
        <div class="secondary-filters"><flux:checkbox wire:model.live="due" :label="__('jobs.due_only')" /><flux:select wire:model.live="archive" :aria-label="__('jobs.archive_filter')"><option value="active">{{ __('jobs.active_records') }}</option><option value="archived">{{ __('jobs.archived_records') }}</option><option value="all">{{ __('jobs.all_records') }}</option></flux:select></div>
        @if($view === 'board')
            <div class="board">
                @foreach(App\Enums\OpportunityStatus::cases() as $column)
                    <div class="board-column"><h3><span class="status-dot"></span>{{ $column->label() }}</h3>
                    @foreach($this->opportunities->where('status', $column) as $job)
                        <article class="board-card" wire:key="board-{{ $job->id }}"><a href="/opportunities/{{ $job->id }}" wire:navigate><strong>{{ $job->company }}</strong><p>{{ $job->title ?: __('jobs.untitled_role') }}</p></a><small>{{ __('jobs.want') }} {{ $job->desirability ?? '—' }}/5 · {{ __('jobs.fit') }} {{ $job->skill_match ?? '—' }}/5</small><select aria-label="{{ __('jobs.change_status') }}" wire:change="changeStatus({{ $job->id }}, $event.target.value)">@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}" @selected($job->status === $option)>{{ $option->label() }}</option>@endforeach</select></article>
                    @endforeach
                    </div>
                @endforeach
            </div>
            <p class="board-note">{{ __('jobs.board_hint') }}</p>
        @else
            <div class="table-scroll"><table><thead><tr><th>{{ __('jobs.role_company') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.location') }}</th><th>{{ __('jobs.compensation') }}</th><th>{{ __('jobs.want') }}</th><th>{{ __('jobs.fit') }}</th><th>{{ __('jobs.follow_up') }}</th></tr></thead><tbody>
                @forelse($this->opportunities as $job)
                    <tr wire:key="row-{{ $job->id }}"><td><a class="role-link" href="/opportunities/{{ $job->id }}" wire:navigate>{{ $job->title ?: __('jobs.untitled_role') }}</a><span class="company-name">{{ $job->company }}@if($job->archived_at) · {{ __('jobs.archived') }}@endif</span></td><td><select class="status-select status-{{ $job->status->value }}" aria-label="{{ __('jobs.status_for', ['company' => $job->company]) }}" wire:change="changeStatus({{ $job->id }}, $event.target.value)">@foreach(App\Enums\OpportunityStatus::cases() as $option)<option value="{{ $option->value }}" @selected($job->status === $option)>{{ $option->label() }}</option>@endforeach</select></td><td>{{ $job->location ?: '—' }}<span class="company-name">{{ $job->work_arrangement->label() }}</span></td><td class="salary">@if($job->salary_min !== null || $job->salary_max !== null){{ $job->currency }} {{ $job->salary_min !== null ? number_format((float)$job->salary_min, 0) : '—' }}–{{ $job->salary_max !== null ? number_format((float)$job->salary_max, 0) : '—' }}<span class="company-name">{{ $job->pay_period->label() }}</span>@else<span class="muted">{{ __('jobs.not_listed') }}</span>@endif</td><td><span class="rank-score">{{ $job->desirability ?? '—' }}</span></td><td><span class="rank-score secondary">{{ $job->skill_match ?? '—' }}</span></td><td>{{ $job->follow_up_at?->format('M j, Y') ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="7"><div class="table-empty"><h3>{{ __('jobs.pipeline_empty_title') }}</h3><p>{{ __('jobs.pipeline_empty') }}</p></div></td></tr>
                @endforelse
            </tbody></table></div>
        @endif
        <div class="pagination">{{ $this->opportunities->links() }}</div>
    </section>
</div>
