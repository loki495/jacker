<?php

declare(strict_types=1);

use App\Models\LinkedInLink;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    /** @var list<int> */
    public array $selected = [];

    public string $message = '';

    public function queueSelected(): void
    {
        $ids = array_values(array_unique(array_map(intval(...), $this->selected)));
        if ($ids === []) {
            $this->message = __('jobs.linkedin_nothing_selected');

            return;
        }

        $queued = LinkedInLink::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [LinkedInLink::NEW, LinkedInLink::FAILED])
            ->update(['status' => LinkedInLink::QUEUED, 'scan_error' => null]);

        $this->selected = [];
        unset($this->links);
        $this->message = trans_choice('jobs.linkedin_queued', $queued, ['count' => $queued]);
    }

    public function dismissLink(int $id): void
    {
        LinkedInLink::query()->whereKey($id)->whereIn('status', [LinkedInLink::NEW, LinkedInLink::FAILED])->update(['status' => LinkedInLink::DISMISSED]);
        unset($this->links);
        $this->message = __('jobs.linkedin_dismissed');
    }

    #[Computed]
    public function links()
    {
        return LinkedInLink::query()->where('status', '!=', LinkedInLink::DISMISSED)->orderByDesc('id')->paginate(25, pageName: 'linksPage');
    }

    #[Computed]
    public function waiting(): int
    {
        return LinkedInLink::query()->whereIn('status', [LinkedInLink::QUEUED, LinkedInLink::SCANNING])->count();
    }
};
?>

<div wire:poll.{{ $this->waiting > 0 ? '3s' : '30s' }}>
    <div class="page-heading">
        <div>
            <div class="eyebrow">{{ __('jobs.workspace') }}</div>
            <h1>{{ __('jobs.linkedin_title') }}</h1>
            <p>{{ __('jobs.linkedin_intro') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="$set('selected', [])" :disabled="$selected === []">{{ __('jobs.linkedin_clear_selection') }}</flux:button>
            <flux:button wire:click="queueSelected" variant="primary" icon="play" :disabled="$selected === []">{{ __('jobs.linkedin_scan_selected') }}</flux:button>
        </div>
    </div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif

    <section class="panel">
        <div class="section-heading">
            <div><h2>{{ __('jobs.linkedin_list') }}</h2><p>{{ __('jobs.linkedin_running', ['count' => $this->waiting]) }}</p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr>
                    <th></th>
                    <th>{{ __('jobs.linkedin_col_role') }}</th>
                    <th>{{ __('jobs.linkedin_col_company') }}</th>
                    <th>{{ __('jobs.linkedin_col_location') }}</th>
                    <th>{{ __('jobs.status') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @forelse($this->links as $link)
                    <tr wire:key="link-{{ $link->id }}">
                        <td>
                            @if(in_array($link->status, ['new', 'failed'], true))
                                <input type="checkbox" wire:model="selected" value="{{ $link->id }}" aria-label="{{ __('jobs.linkedin_select', ['title' => $link->title ?: $link->url]) }}">
                            @endif
                        </td>
                        <td><a class="underline" href="{{ $link->url }}" target="_blank" rel="noopener">{{ $link->title ?: $link->job_id }}</a></td>
                        <td>{{ $link->company }}</td>
                        <td>{{ $link->location }}</td>
                        <td>
                            {{ __('jobs.linkedin_status_'.$link->status) }}
                            @if($link->scan_error)<small class="block">{{ $link->scan_error }}</small>@endif
                            @if($link->opportunity_id)<a class="underline" href="/opportunities/{{ $link->opportunity_id }}" wire:navigate>{{ __('jobs.linkedin_open_listing') }}</a>@endif
                        </td>
                        <td>
                            @if(in_array($link->status, ['new', 'failed'], true))
                                <flux:button wire:click="dismissLink({{ $link->id }})" wire:confirm="{{ __('jobs.linkedin_dismiss_confirm') }}">{{ __('jobs.linkedin_dismiss') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('jobs.linkedin_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $this->links->links() }}
    </section>
</div>
