<?php

declare(strict_types=1);

use App\Actions\QueueLinkChecks;
use App\Actions\RequestAiExtraction;
use App\Enums\LinkCheckStatus;
use App\Models\LinkCheck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $message = '';

    public string $clearDays = '30';

    public function checkAll(QueueLinkChecks $queue): void
    {
        $count = $queue->forActiveJobs();
        $this->message = $count > 0 ? __('jobs.link_queued_count', ['count' => $count]) : __('jobs.link_nothing_queued');
    }

    public function clearOld(): void
    {
        $days = (int) $this->clearDays;
        $deleted = LinkCheck::query()
            ->whereIn('status', [LinkCheckStatus::Done, LinkCheckStatus::Failed])
            ->whereDoesntHave('aiRequests', fn ($query) => $query->where('status', LinkCheckStatus::Running))
            ->when($days > 0, fn ($query) => $query->where('created_at', '<', now()->subDays($days)))
            ->delete();
        $this->message = __('jobs.link_cleared', ['count' => $deleted]);
        $this->resetPage();
    }

    public function readWithAi(int $id, RequestAiExtraction $request): void
    {
        $check = LinkCheck::query()->find($id);
        $this->message = $check instanceof LinkCheck && $request->handle($check) !== null ? __('jobs.ai_queued') : __('jobs.ai_not_eligible');
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $counts = LinkCheck::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(LinkCheckStatus::cases())->mapWithKeys(fn (LinkCheckStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    #[Computed]
    public function checks()
    {
        return LinkCheck::query()->with('opportunity', 'latestAiRequest')->orderByDesc('id')->paginate(25);
    }
};
?>

<div wire:poll.3s>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.workspace') }}</div><h1>{{ __('jobs.link_checks') }}</h1><p>{{ __('jobs.link_checks_intro') }}</p></div><flux:button wire:click="checkAll" variant="primary" icon="arrow-path">{{ __('jobs.link_check_all') }}</flux:button></div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif
    <section class="metrics" aria-label="{{ __('jobs.link_checks') }}">
        @foreach($this->counts as $status => $total)
            <div class="metric"><span>{{ App\Enums\LinkCheckStatus::from($status)->label() }}</span><strong>{{ $total }}</strong>@if($status === 'queued')<small>{{ __('jobs.link_remaining', ['count' => $this->counts['queued'] + $this->counts['running']]) }}</small>@endif</div>
        @endforeach
    </section>
    <section class="panel detail-panel"><form wire:submit="clearOld" class="form-actions"><flux:select wire:model="clearDays" :aria-label="__('jobs.link_clear_label')"><option value="30">{{ __('jobs.link_clear_30') }}</option><option value="7">{{ __('jobs.link_clear_7') }}</option><option value="0">{{ __('jobs.link_clear_all') }}</option></flux:select><flux:button type="submit" wire:confirm="{{ __('jobs.link_clear_confirm') }}">{{ __('jobs.link_clear') }}</flux:button></form></section>
    <section class="pipeline panel"><div class="table-scroll"><table><thead><tr><th>{{ __('jobs.link_col_when') }}</th><th>{{ __('jobs.link_col_kind') }}</th><th>{{ __('jobs.link_col_job') }}</th><th>{{ __('jobs.link_col_link') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.link_col_result') }}</th></tr></thead><tbody>
        @forelse($this->checks as $check)
            <tr wire:key="check-{{ $check->id }}"><td>{{ $check->created_at->format('M j, g:i a') }}</td><td>{{ __('jobs.link_kind_'.$check->kind) }}</td><td>@if($check->opportunity)<a class="role-link" href="/opportunities/{{ $check->opportunity->id }}" wire:navigate>{{ $check->opportunity->company }}<span class="company-name">{{ $check->opportunity->title }}</span></a>@endif</td><td class="link-cell">@if(preg_match('#^https?://#i', (string) $check->url))<a href="{{ $check->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $check->url }}</a>@else{{ $check->url }}@endif</td><td><span class="status-badge link-{{ $check->status->value }}">{{ $check->status->label() }}</span></td><td>{{ $check->result?->label() }}@if($check->message)<small class="muted"><br>{{ $check->message }}</small>@endif @php($ai = $check->latestAiRequest)@if($ai)<br><small class="muted">{{ __('jobs.ai_state_'.$ai->status->value, ['message' => $ai->message]) }}</small>@if(filled($ai->output))<details><summary><small>{{ __('jobs.ai_raw_reply') }}</small></summary><pre class="link-cell">{{ $ai->output }}</pre></details>@endif@endif @if($check->page_text && ! $check->filled && ! in_array($ai?->status, [App\Enums\LinkCheckStatus::Queued, App\Enums\LinkCheckStatus::Running], true))<br><flux:button size="xs" wire:click="readWithAi({{ $check->id }})">{{ __('jobs.ai_extract') }}</flux:button>@endif</td></tr>
        @empty
            <tr><td colspan="6"><div class="table-empty"><p>{{ __('jobs.link_log_empty') }}</p></div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $this->checks->links() }}</div></section>
</div>
