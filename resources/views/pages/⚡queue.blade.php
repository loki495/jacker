<?php

declare(strict_types=1);

use App\Actions\QueueLinkChecks;
use App\Actions\RequestAiExtraction;
use App\Actions\RequestScoring;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public const array KINDS = ['all', 'links', 'grading'];

    public string $message = '';

    public string $clearDays = '30';

    #[Url]
    public string $kind = 'all';

    public function showKind(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 422);
        $this->kind = $kind;
    }

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
        $this->resetPage('checksPage');
    }

    public function readWithAi(int $id, RequestAiExtraction $request): void
    {
        $check = LinkCheck::query()->find($id);
        $this->message = $check instanceof LinkCheck && $request->handle($check) !== null ? __('jobs.ai_queued') : __('jobs.ai_not_eligible');
    }

    /** @return array<string, int> */
    #[Computed]
    public function linkCounts(): array
    {
        $counts = LinkCheck::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(LinkCheckStatus::cases())->mapWithKeys(fn (LinkCheckStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    #[Computed]
    public function checks()
    {
        return LinkCheck::query()->with('opportunity', 'latestAiRequest', 'latestRenderRequest')->orderByDesc('id')->paginate(25, pageName: 'checksPage');
    }

    public function rescore(string $mode, RequestScoring $scoring): void
    {
        abort_unless(in_array($mode, [RequestScoring::ALL, RequestScoring::UNSCORED], true), 422);
        $count = $scoring->handle($mode);
        $this->message = $count > 0 ? __('jobs.score_queued', ['count' => $count]) : __('jobs.score_nothing_queued');
        $this->resetPage('requestsPage');
    }

    public function clearDone(): void
    {
        $deleted = AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->where('status', LinkCheckStatus::Done)->delete();
        $this->message = __('jobs.score_cleared', ['count' => $deleted]);
        $this->resetPage('requestsPage');
    }

    /** @return array<string, int> */
    #[Computed]
    public function scoreCounts(): array
    {
        $counts = AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(LinkCheckStatus::cases())->mapWithKeys(fn (LinkCheckStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    #[Computed]
    public function requests()
    {
        return AiRequest::query()->with('opportunity')->where('kind', AiRequest::KIND_SCORE)->orderByDesc('id')->paginate(25, pageName: 'requestsPage');
    }
};
?>

<div wire:poll.3s>
    <div class="page-heading"><div><div class="eyebrow">{{ __('jobs.workspace') }}</div><h1>{{ __('jobs.queue_title') }}</h1><p>{{ __('jobs.queue_intro') }}</p></div><div class="view-switch" role="group" aria-label="{{ __('jobs.queue_filter') }}">@foreach(['all', 'links', 'grading'] as $option)<button type="button" wire:click="showKind('{{ $option }}')" @class(['active' => $kind === $option]) aria-pressed="{{ $kind === $option ? 'true' : 'false' }}">{{ __('jobs.queue_kind_'.$option) }}</button>@endforeach</div></div>
    @if($message)<div class="success-message" role="status">{{ $message }}</div>@endif
    @if($kind !== 'grading')
    <div class="mb-8">
        <div class="section-heading"><div><h2>{{ __('jobs.link_checks') }}</h2><p>{{ __('jobs.link_checks_intro') }}</p></div><flux:button wire:click="checkAll" variant="primary" icon="arrow-path">{{ __('jobs.link_check_all') }}</flux:button></div>
        <section class="metrics" aria-label="{{ __('jobs.link_checks') }}">
            @foreach($this->linkCounts as $status => $total)
                <div class="metric"><span>{{ App\Enums\LinkCheckStatus::from($status)->label() }}</span><strong>{{ $total }}</strong>@if($status === 'queued')<small>{{ __('jobs.link_remaining', ['count' => $this->linkCounts['queued'] + $this->linkCounts['running']]) }}</small>@endif</div>
            @endforeach
        </section>
        <section class="panel detail-panel"><form wire:submit="clearOld" class="form-actions"><flux:select wire:model="clearDays" :aria-label="__('jobs.link_clear_label')"><option value="30">{{ __('jobs.link_clear_30') }}</option><option value="7">{{ __('jobs.link_clear_7') }}</option><option value="0">{{ __('jobs.link_clear_all') }}</option></flux:select><flux:button type="submit" wire:confirm="{{ __('jobs.link_clear_confirm') }}">{{ __('jobs.link_clear') }}</flux:button></form></section>
        <section class="pipeline panel"><div class="table-scroll"><table><thead><tr><th>{{ __('jobs.link_col_when') }}</th><th>{{ __('jobs.link_col_kind') }}</th><th>{{ __('jobs.link_col_job') }}</th><th>{{ __('jobs.link_col_link') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.link_col_result') }}</th></tr></thead><tbody>
            @forelse($this->checks as $check)
                <tr wire:key="check-{{ $check->id }}"><td>{{ $check->created_at->format('M j, g:i a') }}</td><td>{{ __('jobs.link_kind_'.$check->kind) }}</td><td>@if($check->opportunity)<a class="role-link" href="/opportunities/{{ $check->opportunity->id }}" wire:navigate>{{ $check->opportunity->company }}<span class="company-name">{{ $check->opportunity->title }}</span></a>@endif</td><td class="link-cell">@if(preg_match('#^https?://#i', (string) $check->url))<a href="{{ $check->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $check->url }}</a>@else{{ $check->url }}@endif</td><td><span class="status-badge link-{{ $check->status->value }}">{{ $check->status->label() }}</span></td><td>{{ $check->result?->label() }}@if($check->message)<small class="muted"><br>{{ $check->message }}</small>@endif @php($render = $check->latestRenderRequest)@php($pending = [App\Enums\LinkCheckStatus::Queued, App\Enums\LinkCheckStatus::Running])@php($ai = $check->latestAiRequest)@if($render || $ai)<div class="ai-note">@if($render)<small>{{ __('jobs.render_state_'.$render->status->value, ['message' => $render->message]) }}</small>@endif@if($ai)<small>{{ __('jobs.ai_state_'.$ai->status->value, ['message' => $ai->message]) }}</small>@if($ai->replaced)<details wire:ignore wire:key="replaced-{{ $ai->id }}"><summary><small>{{ __('jobs.ai_replaced', ['fields' => implode(', ', array_keys($ai->replaced))]) }}</small></summary>@foreach($ai->replaced as $field => $old)<p><small><b>{{ __('jobs.'.$field) }}</b></small></p><ul class="ai-items">@foreach(App\Models\Opportunity::splitItems($old) as $item)<li>{{ $item }}</li>@endforeach</ul>@endforeach</details>@endif@if(filled($ai->output))<details wire:ignore wire:key="raw-{{ $ai->id }}"><summary><small>{{ __('jobs.ai_raw_reply') }}</small></summary>@if($fields = $ai->outputFields())@foreach($fields as $field => $value)@if(is_scalar($value) && (string) $value !== '')<p><small><b>{{ \Illuminate\Support\Facades\Lang::has('jobs.'.$field) && is_string($label = __('jobs.'.$field)) ? $label : $field }}</b></small></p><ul class="ai-items">@foreach(App\Models\Opportunity::splitItems((string) $value) as $item)<li>{{ $item }}</li>@endforeach</ul>@endif @endforeach @else<pre class="link-cell">{{ $ai->output }}</pre>@endif</details>@endif@endif</div>@endif @if(($check->kind !== 'saved' || $check->page_text) && ! in_array($ai?->status, $pending, true) && ! in_array($render?->status, $pending, true))<br><flux:button size="xs" wire:click="readWithAi({{ $check->id }})">{{ __('jobs.ai_extract') }}</flux:button>@endif</td></tr>
            @empty
                <tr><td colspan="6"><div class="table-empty"><p>{{ __('jobs.link_log_empty') }}</p></div></td></tr>
            @endforelse
        </tbody></table></div><div class="pagination">{{ $this->checks->links() }}</div></section>
    </div>
    @endif
    @if($kind !== 'links')
    <div class="mb-8">
        <div class="section-heading"><div><h2>{{ __('jobs.score_title') }}</h2><p>{{ __('jobs.score_intro') }}</p></div><div class="form-actions"><flux:button wire:click="rescore('unscored')">{{ __('jobs.score_unscored') }}</flux:button><flux:button variant="primary" wire:click="rescore('all')" wire:confirm="{{ __('jobs.score_all_confirm') }}">{{ __('jobs.score_all') }}</flux:button><flux:button wire:click="clearDone" wire:confirm="{{ __('jobs.score_clear_confirm') }}">{{ __('jobs.score_clear') }}</flux:button></div></div>
        <section class="metrics" aria-label="{{ __('jobs.score_title') }}">
            @foreach($this->scoreCounts as $status => $total)
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
    @endif
</div>
