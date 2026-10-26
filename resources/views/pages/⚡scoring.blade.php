<?php

declare(strict_types=1);

use App\Actions\RequestScoring;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $message = '';

    public function rescore(string $mode, RequestScoring $scoring): void
    {
        abort_unless(in_array($mode, [RequestScoring::ALL, RequestScoring::UNSCORED], true), 422);
        $count = $scoring->handle($mode);
        $this->message = $count > 0 ? __('jobs.score_queued', ['count' => $count]) : __('jobs.score_nothing_queued');
        $this->resetPage();
    }

    public function clearDone(): void
    {
        $deleted = AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->where('status', LinkCheckStatus::Done)->delete();
        $this->message = __('jobs.score_cleared', ['count' => $deleted]);
        $this->resetPage();
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $counts = AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(LinkCheckStatus::cases())->mapWithKeys(fn (LinkCheckStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    #[Computed]
    public function requests()
    {
        return AiRequest::query()->with('opportunity')->where('kind', AiRequest::KIND_SCORE)->orderByDesc('id')->paginate(25);
    }
};
?>

<div wire:poll.3s>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.workspace') }}</div><h1>{{ __('jobs.score_title') }}</h1><p>{{ __('jobs.score_intro') }}</p></div><div class="form-actions"><flux:button wire:click="rescore('unscored')">{{ __('jobs.score_unscored') }}</flux:button><flux:button variant="primary" wire:click="rescore('all')" wire:confirm="{{ __('jobs.score_all_confirm') }}">{{ __('jobs.score_all') }}</flux:button><flux:button wire:click="clearDone" wire:confirm="{{ __('jobs.score_clear_confirm') }}">{{ __('jobs.score_clear') }}</flux:button></div></div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif
    <section class="metrics" aria-label="{{ __('jobs.score_title') }}">
        @foreach($this->counts as $status => $total)
            <div class="metric"><span>{{ App\Enums\LinkCheckStatus::from($status)->label() }}</span><strong>{{ $total }}</strong></div>
        @endforeach
    </section>
    <section class="pipeline panel"><div class="table-scroll"><table><thead><tr><th>{{ __('jobs.link_col_when') }}</th><th>{{ __('jobs.link_col_job') }}</th><th>{{ __('jobs.score_col_mode') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.score_col_before') }}</th><th>{{ __('jobs.score_col_after') }}</th><th>{{ __('jobs.score_col_by') }}</th></tr></thead><tbody>
        @forelse($this->requests as $request)
            @php($done = $request->status === App\Enums\LinkCheckStatus::Done)
            <tr wire:key="score-{{ $request->id }}"><td>{{ $request->created_at->format('M j, g:i a') }}</td><td>@if($request->opportunity)<a class="role-link" href="/opportunities/{{ $request->opportunity->id }}" wire:navigate>{{ $request->opportunity->company }}<span class="company-name">{{ $request->opportunity->title }}</span></a>@endif</td><td>{{ __('jobs.score_mode_'.$request->mode) }}</td><td><span class="status-badge link-{{ $request->status->value }}">{{ $request->status->label() }}</span>@if($request->status === App\Enums\LinkCheckStatus::Failed)<small class="muted"><br>{{ $request->message }}</small>@endif</td><td>{{ __('jobs.fit') }} {{ $request->replaced['skill_match'] ?? '—' }} · {{ __('jobs.want') }} {{ $request->replaced['desirability'] ?? '—' }}</td><td>@if($done){{ $request->message }}@endif</td><td>{{ $done ? $request->agent.' / '.$request->model : '' }}</td></tr>
        @empty
            <tr><td colspan="7"><div class="table-empty"><p>{{ __('jobs.score_empty') }}</p></div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $this->requests->links() }}</div></section>
</div>
