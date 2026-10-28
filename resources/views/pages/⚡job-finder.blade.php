<?php

declare(strict_types=1);

use App\Actions\AcceptFoundJob;
use App\Actions\StartScrapeRuns;
use App\Enums\FoundJobStatus;
use App\Enums\LinkCheckStatus;
use App\Models\FoundJob;
use App\Models\ScrapeRun;
use App\Support\LocationSort;
use App\Support\PaySort;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $message = '';

    #[Url]
    public string $filter = 'new';

    #[Url]
    public string $sort = 'posted';

    #[Url]
    public string $source = 'all';

    public function run(string $source, StartScrapeRuns $start): void
    {
        $count = $start->handle($source === 'all' ? array_keys((array) config('scrapers.sources')) : [$source]);
        $this->message = $count > 0 ? __('scrapers.queued', ['count' => $count]) : __('scrapers.busy');
    }

    /** Ids ticked on any page; they stay ticked while paging and are cleared once imported. @var list<int> */
    public array $selected = [];

    public function addSelected(AcceptFoundJob $accept): void
    {
        $ids = array_values(array_unique(array_map(intval(...), $this->selected)));
        if ($ids === []) {
            $this->message = __('scrapers.nothing_selected');

            return;
        }
        $added = 0;
        foreach (FoundJob::query()->whereKey($ids)->where('status', FoundJobStatus::New)->orderBy('id')->get() as $found) {
            try {
                $accept->handle($found);
                $added++;
            } catch (ValidationException) {
                // A link already in the tracker: AcceptFoundJob has dismissed the listing.
            }
        }
        $skipped = count($ids) - $added;
        $this->message = $skipped > 0 ? __('scrapers.added_skipped', ['added' => $added, 'skipped' => $skipped]) : __('scrapers.added_count', ['added' => $added]);
        $this->selected = [];
        unset($this->listings);
        if ($this->getPage() > 1 && $this->listings->isEmpty()) {
            $this->previousPage();
            unset($this->listings);
        }
    }

    public function dismiss(int $id): void
    {
        $this->selected = array_values(array_diff($this->selected, [$id]));
        FoundJob::query()->whereKey($id)->where('status', FoundJobStatus::New)->update(['status' => FoundJobStatus::Dismissed]);
    }

    public function restore(int $id): void
    {
        FoundJob::query()->whereKey($id)->where('status', FoundJobStatus::Dismissed)->update(['status' => FoundJobStatus::New]);
    }

    public function clearRuns(): void
    {
        ScrapeRun::query()->whereIn('status', [LinkCheckStatus::Done, LinkCheckStatus::Failed])->delete();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    #[Computed]
    public function busy(): bool
    {
        return ScrapeRun::active();
    }

    #[Computed]
    public function runs()
    {
        return ScrapeRun::query()->orderByDesc('id')->limit(12)->get();
    }

    #[Computed]
    public function listings()
    {
        return FoundJob::query()
            ->when($this->filter !== 'all', fn ($query) => $query->where('status', $this->filter))
            ->when(array_key_exists($this->source, (array) config('scrapers.sources')), fn ($query) => $query->where('source', $this->source))
            ->tap(fn ($query) => match ($this->sort) {
                'pay' => $query->orderByRaw(PaySort::orderBy()),
                'location' => $query->orderByRaw(LocationSort::usFirst()),
                default => $query->orderByRaw('posted_at IS NULL')->orderByDesc('posted_at'),
            })
            ->orderByDesc('id')->paginate(25);
    }
};
?>

<div wire:poll.3s>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.workspace') }}</div><h1>{{ __('scrapers.title') }}</h1><p>{{ __('scrapers.intro', ['pages' => config('scrapers.max_pages'), 'spacing' => config('scrapers.spacing')]) }}</p></div><flux:button wire:click="run('all')" variant="primary" icon="arrow-path" :disabled="$this->busy">{{ __('scrapers.run_all') }}</flux:button></div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif
    <section class="panel detail-panel"><div class="flex flex-wrap gap-2">
        @foreach(array_keys(config('scrapers.sources')) as $source)
            <flux:button size="sm" wire:click="run('{{ $source }}')" :disabled="$this->busy">{{ __('scrapers.run_one', ['source' => __('scrapers.source_'.$source)]) }}</flux:button>
        @endforeach
    </div></section>
    <section class="pipeline panel"><div class="pipeline-header"><div><h2>{{ __('scrapers.runs') }}</h2></div>@if($this->runs->isNotEmpty() && ! $this->busy)<flux:button size="xs" wire:click="clearRuns">{{ __('scrapers.clear_runs') }}</flux:button>@endif</div><div class="table-scroll"><table><thead><tr><th>{{ __('scrapers.col_when') }}</th><th>{{ __('scrapers.col_source') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('scrapers.col_pages') }}</th><th>{{ __('scrapers.col_seen') }}</th><th>{{ __('scrapers.col_added') }}</th><th>{{ __('scrapers.col_result') }}</th></tr></thead><tbody>
        @forelse($this->runs as $run)
            <tr wire:key="run-{{ $run->id }}"><td>{{ $run->created_at->format('M j, g:i a') }}</td><td>{{ __('scrapers.source_'.$run->source) }}</td><td><span class="status-badge link-{{ $run->status->value }}">{{ $run->status->label() }}</span></td><td>{{ $run->pages_done }} / {{ $run->pages_total }}</td><td>{{ $run->seen }}</td><td>{{ $run->added }}</td><td>{{ $run->message }}</td></tr>
        @empty
            <tr><td colspan="7"><div class="table-empty"><p>{{ __('scrapers.no_runs') }}</p></div></td></tr>
        @endforelse
    </tbody></table></div></section>
    <section class="pipeline panel mt-4">
        <div class="pipeline-header flex-wrap">
            <div><h2>{{ __('scrapers.listings') }}</h2></div>
            <flux:button size="sm" variant="primary" wire:click="addSelected" :disabled="$selected === []">{{ __('scrapers.add_selected') }}@if($selected !== []) ({{ count($selected) }})@endif</flux:button>
        </div>
        <div class="found-listings-filters">
            <flux:select wire:model.live="filter" :aria-label="__('scrapers.listings')">
                <option value="new">{{ __('scrapers.FoundJobStatus.new') }}</option>
                <option value="dismissed">{{ __('scrapers.FoundJobStatus.dismissed') }}</option>
                <option value="accepted">{{ __('scrapers.FoundJobStatus.accepted') }}</option>
                <option value="all">{{ __('scrapers.filter_all') }}</option>
            </flux:select>
            <flux:select wire:model.live="source" :aria-label="__('scrapers.col_source')">
                <option value="all">{{ __('scrapers.filter_all_sources') }}</option>
                @foreach(array_keys(config('scrapers.sources')) as $option)
                    <option value="{{ $option }}">{{ __('scrapers.source_'.$option) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="sort" :aria-label="__('scrapers.sort')">
                @foreach(['posted', 'pay', 'location'] as $option)
                    <option value="{{ $option }}">{{ __('scrapers.sort_'.$option) }}</option>
                @endforeach
            </flux:select>
        </div>
        <div class="table-scroll"><table><thead><tr><th></th><th>{{ __('scrapers.col_job') }}</th><th>{{ __('scrapers.col_source') }}</th><th>{{ __('jobs.location') }}</th><th>{{ __('scrapers.col_pay') }}</th><th>{{ __('scrapers.col_posted') }}</th><th></th></tr></thead><tbody>
        @forelse($this->listings as $found)
            <tr wire:key="found-{{ $found->id }}"><td>@if($found->status === App\Enums\FoundJobStatus::New)<input type="checkbox" wire:model.live="selected" value="{{ $found->id }}" aria-label="{{ __('scrapers.select_row') }}">@endif</td><td><a class="role-link" href="{{ $found->url }}" target="_blank" rel="noopener noreferrer">{{ $found->company ?? __('scrapers.unknown_company') }}<span class="company-name">{{ $found->title }}</span></a></td><td>{{ __('scrapers.source_'.$found->source) }}</td><td>{{ \Illuminate\Support\Str::limit((string) $found->location, 60) }}</td><td>@if($found->salary_min){{ number_format((float) $found->salary_min) }}@if($found->salary_max) – {{ number_format((float) $found->salary_max) }}@endif {{ $found->currency }}@else{{ $found->salary_notes }}@endif</td><td>{{ $found->posted_at?->format('M j') }}</td><td>
                @if($found->status === App\Enums\FoundJobStatus::New)
                    <flux:button size="xs" wire:click="dismiss({{ $found->id }})">{{ __('scrapers.dismiss') }}</flux:button>
                @elseif($found->status === App\Enums\FoundJobStatus::Dismissed)
                    <flux:button size="xs" wire:click="restore({{ $found->id }})">{{ __('scrapers.restore') }}</flux:button>
                @elseif($found->opportunity_id)
                    <a class="underline" href="/opportunities/{{ $found->opportunity_id }}" wire:navigate>{{ __('scrapers.open_tracker') }}</a>
                @endif
            </td></tr>
        @empty
            <tr><td colspan="7"><div class="table-empty"><p>{{ __('scrapers.no_listings') }}</p></div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $this->listings->links() }}</div></section>
</div>
