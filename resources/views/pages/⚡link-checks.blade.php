<?php

declare(strict_types=1);

use App\Actions\ImportSavedPage;
use App\Actions\QueueLinkChecks;
use App\Actions\RequestAiExtraction;
use App\Enums\LinkCheckStatus;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $urls = '';

    public string $message = '';

    public string $savedText = '';

    public string $savedUrl = '';

    public string $savedJob = '';

    public $savedFile;

    public function checkAll(QueueLinkChecks $queue): void
    {
        $count = $queue->forActiveJobs();
        $this->message = $count > 0 ? __('jobs.link_queued_count', ['count' => $count]) : __('jobs.link_nothing_queued');
    }

    public function readWithAi(int $id, RequestAiExtraction $request): void
    {
        $check = LinkCheck::query()->find($id);
        $this->message = $check instanceof LinkCheck && $request->handle($check) !== null ? __('jobs.ai_queued') : __('jobs.ai_not_eligible');
    }

    public function importSaved(ImportSavedPage $import): void
    {
        $this->validate([
            'savedFile' => ['nullable', 'file', 'max:'.(int) ceil((int) config('linkcheck.max_bytes') / 1024), 'extensions:html,htm,mhtml,mht,txt'],
            'savedUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'savedJob' => ['nullable', 'exists:opportunities,id'],
        ]);
        $content = $this->savedFile?->get() ?? $this->savedText;
        if (trim((string) $content) === '') {
            $this->addError('savedText', __('jobs.saved_need_input'));

            return;
        }
        $check = $import->handle((string) $content, $this->savedUrl ?: null, $this->savedJob === '' ? null : Opportunity::query()->find((int) $this->savedJob));
        $this->message = $check->message ?? __('jobs.link_created');
        $this->reset('savedText', 'savedUrl', 'savedJob', 'savedFile');
    }

    public function addUrls(QueueLinkChecks $queue): void
    {
        $this->validate(['urls' => ['required', 'string', 'max:20000']], ['urls.required' => __('jobs.urls_required')]);
        $result = $queue->forUrls(preg_split('/\R/', $this->urls) ?: []);
        $this->message = __('jobs.link_queued_count', ['count' => $result['queued']]).($result['rejected'] ? ' '.__('jobs.urls_rejected', ['urls' => implode(', ', $result['rejected'])]) : '');
        $this->urls = '';
    }

    #[Computed]
    public function jobs()
    {
        return Opportunity::query()->whereNull('archived_at')->orderBy('company')->orderBy('title')->get(['id', 'company', 'title']);
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
        return LinkCheck::query()->with('opportunity')->withExists(['aiRequests as ai_pending' => fn ($query) => $query->whereIn('status', ['queued', 'running'])])->orderByDesc('id')->paginate(25);
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
    <section class="panel detail-panel"><h2>{{ __('jobs.add_from_urls') }}</h2><p class="field-hint">{{ __('jobs.add_from_urls_hint') }}</p>
        <form wire:submit="addUrls"><flux:textarea wire:model="urls" rows="4" :placeholder="__('jobs.urls_placeholder')" :aria-label="__('jobs.add_from_urls')" />@error('urls')<p class="error-text">{{ $message }}</p>@enderror<div class="form-actions"><span></span><flux:button type="submit" variant="primary">{{ __('jobs.add_from_urls_submit') }}</flux:button></div></form>
    </section>
    <section class="panel detail-panel"><h2>{{ __('jobs.saved_title') }}</h2><p class="field-hint">{{ __('jobs.saved_hint') }}</p>
        <form wire:submit="importSaved">
            <flux:textarea wire:model="savedText" rows="5" :placeholder="__('jobs.saved_text')" :aria-label="__('jobs.saved_text')" />@error('savedText')<p class="error-text">{{ $message }}</p>@enderror
            <div class="form-grid"><div><flux:input type="file" wire:model="savedFile" accept=".html,.htm,.mhtml,.mht,.txt" :label="__('jobs.saved_file')" />@error('savedFile')<p class="error-text">{{ $message }}</p>@enderror</div>
            <div><flux:input wire:model="savedUrl" type="url" :label="__('jobs.saved_url')" />@error('savedUrl')<p class="error-text">{{ $message }}</p>@enderror</div>
            <div class="wide-field"><flux:select wire:model="savedJob" :label="__('jobs.saved_job')"><flux:select.option value="">{{ __('jobs.saved_job_new') }}</flux:select.option>@foreach($this->jobs as $job)<flux:select.option value="{{ $job->id }}">{{ $job->company }} — {{ $job->title }}</flux:select.option>@endforeach</flux:select></div></div>
            <div class="form-actions"><span></span><flux:button type="submit" variant="primary">{{ __('jobs.saved_submit') }}</flux:button></div></form>
    </section>
    <section class="pipeline panel"><div class="table-scroll"><table><thead><tr><th>{{ __('jobs.link_col_when') }}</th><th>{{ __('jobs.link_col_kind') }}</th><th>{{ __('jobs.link_col_link') }}</th><th>{{ __('jobs.status') }}</th><th>{{ __('jobs.link_col_result') }}</th><th>{{ __('jobs.link_col_job') }}</th></tr></thead><tbody>
        @forelse($this->checks as $check)
            <tr wire:key="check-{{ $check->id }}"><td>{{ $check->created_at->format('M j, g:i a') }}</td><td>{{ __('jobs.link_kind_'.$check->kind) }}</td><td class="link-cell">{{ $check->url }}</td><td><span class="status-badge link-{{ $check->status->value }}">{{ $check->status->label() }}</span></td><td>{{ $check->result?->label() }}@if($check->message)<small class="muted"><br>{{ $check->message }}</small>@endif @if($check->page_text && ! $check->filled && ! $check->ai_pending)<br><flux:button size="xs" wire:click="readWithAi({{ $check->id }})">{{ __('jobs.ai_extract') }}</flux:button>@endif</td><td>@if($check->opportunity)<a class="role-link" href="/opportunities/{{ $check->opportunity->id }}" wire:navigate>{{ $check->opportunity->company }}<span class="company-name">{{ $check->opportunity->title }}</span></a>@endif</td></tr>
        @empty
            <tr><td colspan="6"><div class="table-empty"><p>{{ __('jobs.link_log_empty') }}</p></div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $this->checks->links() }}</div></section>
</div>
