<?php

declare(strict_types=1);

use App\Jobs\ScanMailboxJob;
use App\Models\LinkedInLink;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    /** @var list<int> */
    public array $selected = [];

    public string $message = '';

    public function scanMailbox(): void
    {
        if (blank(config('mailscan.username')) || blank(config('mailscan.password'))) {
            $this->message = __('jobs.mail_not_configured');

            return;
        }
        ScanMailboxJob::dispatch();
        $this->message = __('jobs.mail_scan_queued');
    }

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

    public function retryFailed(): void
    {
        $count = LinkedInLink::query()->where('status', LinkedInLink::FAILED)->update(['status' => LinkedInLink::QUEUED, 'scan_error' => null]);
        unset($this->links, $this->needsSignIn);
        $this->message = trans_choice('jobs.linkedin_queued', $count, ['count' => $count]);
    }

    public function rescan(int $id): void
    {
        LinkedInLink::query()->whereKey($id)->where('status', LinkedInLink::IMPORTED)->update(['status' => LinkedInLink::QUEUED, 'scan_error' => null]);
        unset($this->links);
        $this->message = trans_choice('jobs.linkedin_queued', 1, ['count' => 1]);
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
    public function needsSignIn(): bool
    {
        return LinkedInLink::query()->where('status', LinkedInLink::FAILED)->where('scan_error', 'like', '%asked for a sign-in%')->exists();
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
            <h1>{{ __('jobs.mail_leads_title') }}</h1>
            <p>{{ __('jobs.mail_leads_intro') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="scanMailbox" icon="envelope" wire:loading.attr="disabled" wire:target="scanMailbox">{{ __('jobs.mail_scan') }}</flux:button>
            <flux:button wire:click="retryFailed" icon="arrow-path">{{ __('jobs.linkedin_retry_failed') }}</flux:button>
            <flux:button wire:click="$set('selected', [])" :disabled="$selected === []">{{ __('jobs.linkedin_clear_selection') }}</flux:button>
            <flux:button wire:click="queueSelected" variant="primary" icon="play" :disabled="$selected === []">{{ __('jobs.linkedin_scan_selected') }}</flux:button>
        </div>
    </div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif
    @if($this->needsSignIn)<div class="validation-summary" role="alert">{{ __('jobs.linkedin_needs_sign_in') }}</div>@endif

    <section class="panel">
        <div class="pipeline-header">
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
                            @if($link->status === 'imported' && ! $link->apply_url)
                                <flux:button wire:click="rescan({{ $link->id }})">{{ __('jobs.linkedin_rescan') }}</flux:button>
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
