<?php

declare(strict_types=1);

use App\Actions\CsvImport;
use App\Actions\CsvPreview;
use App\Actions\ImportSavedPage;
use App\Actions\QueueLinkChecks;
use App\Models\Opportunity;
use App\Support\CsvMapping;
use App\Support\CsvReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    private const SAVED_EXTENSIONS = ['html', 'htm', 'mhtml', 'mht', 'txt'];

    public $upload;

    public string $urls = '';

    public string $message = '';

    public string $savedText = '';

    public string $savedUrl = '';

    #[Url(as: 'job')]
    public string $savedJob = '';

    #[Locked]
    public string $token = '';

    #[Locked]
    public array $headers = [];

    public array $mapping = [];

    public array $excluded = [];

    public array $includedCandidates = [];

    #[Locked]
    public bool $previewing = false;

    public int $page = 1;

    #[Locked]
    public string $result = '';

    public function render()
    {
        return $this->view()->title(__('csv.page_title'));
    }

    public function loadUpload(): void
    {
        if ($this->uploadIsSavedPage()) {
            $this->importSaved(app(ImportSavedPage::class));

            return;
        }
        $this->validate(['upload' => ['required', 'file', 'max:'.(int) ceil((int) config('csv.max_bytes', 5 * 1024 * 1024) / 1024)]]);
        $csv = app(CsvReader::class)->read($this->upload->getRealPath());
        $this->discardUpload();
        $this->token = (string) Str::uuid();
        $path = $this->upload->storeAs('csv-imports', $this->token.'.csv', 'local');
        session()->put('csv-import.'.$this->token, $path);
        $this->headers = $csv['headers'];
        $this->mapping = app(CsvMapping::class)->suggest($this->headers);
        $this->excluded = $this->includedCandidates = [];
        $this->previewing = false;
        $this->page = 1;
        $this->result = '';
        $this->reset('upload');
        $this->resetValidation();
        $this->js("document.getElementById('csv-mapping')?.scrollIntoView({behavior: 'smooth'})");
    }

    public function importSaved(ImportSavedPage $import): void
    {
        $this->validate([
            'savedUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'savedJob' => ['nullable', 'exists:opportunities,id'],
        ]);
        $file = $this->uploadIsSavedPage() ? $this->upload : null;
        if ($file !== null) {
            $this->validate(['upload' => ['file', 'max:'.(int) ceil((int) config('linkcheck.max_bytes') / 1024)]]);
        }
        $content = $file?->get() ?? $this->savedText;
        if (trim((string) $content) === '') {
            $this->addError('savedText', __('jobs.saved_need_input'));

            return;
        }
        $check = $import->handle((string) $content, $this->savedUrl ?: null, $this->savedJob === '' ? null : Opportunity::query()->find((int) $this->savedJob));
        $this->message = $check->message ?? __('jobs.link_created');
        $this->reset('upload', 'savedText', 'savedUrl', 'savedJob');
        $this->resetValidation();
        $this->scrollToTop();
    }

    private function scrollToTop(): void
    {
        $this->js('window.scrollTo({top: 0, behavior: "smooth"})');
    }

    public function addUrls(QueueLinkChecks $queue): void
    {
        $this->validate(['urls' => ['required', 'string', 'max:20000']], ['urls.required' => __('jobs.urls_required')]);
        $result = $queue->forUrls(preg_split('/\R/', $this->urls) ?: []);
        $this->message = __('jobs.link_queued_count', ['count' => $result['queued']]).($result['rejected'] ? ' '.__('jobs.urls_rejected', ['urls' => implode(', ', $result['rejected'])]) : '');
        $this->urls = '';
        $this->scrollToTop();
    }

    public function mount(): void
    {
        if ($this->savedJob !== '' && ! $this->jobs->contains('id', (int) $this->savedJob)) {
            $this->savedJob = '';
        }
    }

    #[Computed]
    public function jobs()
    {
        return Opportunity::query()->whereNull('archived_at')->orderBy('company')->orderBy('title')->get(['id', 'company', 'title']);
    }

    public function updatedMapping(): void
    {
        $this->previewing = false;
        $this->excluded = $this->includedCandidates = [];
        $this->page = 1;
    }

    public function preview(): void
    {
        app(CsvPreview::class)->handle($this->canonicalPath(), $this->mapping);
        $this->previewing = true;
        $this->resetValidation();
    }

    #[Computed]
    public function previewData(): array
    {
        if (! $this->previewing) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
        try {
            $rows = app(CsvPreview::class)->handle($this->canonicalPath(), $this->mapping);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
            }
            $this->previewing = false;

            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
        $pages = max(1, (int) ceil(count($rows) / 20));
        $page = max(1, min($this->page, $pages));

        return ['rows' => array_slice($rows, ($page - 1) * 20, 20), 'total' => count($rows), 'pages' => $pages];
    }

    public function importReviewed(): void
    {
        if (! $this->previewing) {
            throw ValidationException::withMessages(['import' => __('csv.preview_required')]);
        }
        $result = app(CsvImport::class)->handle($this->canonicalPath(), $this->mapping, $this->excluded, $this->includedCandidates);
        $this->result = __('csv.result', $result);
        $this->scrollToTop();
        $this->discardUpload();
        $this->headers = $this->mapping = $this->excluded = $this->includedCandidates = [];
        $this->previewing = false;
        unset($this->previewData);
    }

    private function uploadIsSavedPage(): bool
    {
        return $this->upload !== null
            && in_array(strtolower(pathinfo($this->upload->getClientOriginalName(), PATHINFO_EXTENSION)), self::SAVED_EXTENSIONS, true);
    }

    private function canonicalPath(): string
    {
        $path = session()->get('csv-import.'.$this->token);
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['upload' => __('csv.expired')]);
        }

        return Storage::disk('local')->path($path);
    }

    private function discardUpload(): void
    {
        $path = session()->pull('csv-import.'.$this->token);
        if (is_string($path)) {
            Storage::disk('local')->delete($path);
        }
        $this->token = '';
    }
}; ?>

<div class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('csv.heading') }}</flux:heading>
        <flux:text class="mt-2">{{ __('csv.intro') }}</flux:text>
    </div>
    @if ($message)
        <p role="status" class="success-message mb-8!">{{ $message }} <a href="/link-checks" class="underline" wire:navigate>{{ __('jobs.link_checks') }}</a></p>
    @endif
    @if ($result)
        <p role="status" class="success-message mb-8!">{{ $result }} <a href="/" class="underline">{{ __('csv.open_workspace') }}</a></p>
    @endif
    <form wire:submit="loadUpload" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:input type="file" wire:model="upload" :label="__('csv.file')" accept=".csv,.tsv,.html,.htm,.mhtml,.mht,.txt,text/csv" />
        <flux:text>{{ __('csv.file_hint') }}</flux:text>
        <flux:text>{{ __('csv.limits', ['mb' => number_format((int) config('csv.max_bytes', 5242880) / 1048576, 1), 'rows' => config('csv.max_rows', 1000)]) }}</flux:text>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('csv.read_file') }}</flux:button>
    </form>
    @error('upload') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror

    @if ($headers)
        <form wire:submit="preview" id="csv-mapping" class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('csv.match_columns') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($headers as $index => $header)
                    <flux:select wire:model.live="mapping.{{ $index }}" label="{{ ucfirst(str_replace('_', ' ', $header)) }}" wire:key="column-{{ $index }}">
                        <flux:select.option value="">{{ __('csv.keep_extra') }}</flux:select.option>
                        @foreach (CsvMapping::FIELDS as $field)
                            <flux:select.option value="{{ $field }}">{{ $field === 'archived_at' ? __('csv.archived_at') : __('jobs.'.$field) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endforeach
            </div>
            @error('mapping') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary">{{ __('csv.preview') }}</flux:button>
        </form>
    @endif


    @if ($previewing)
        @php($preview = $this->previewData)
        <section class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('csv.review_records', ['total' => $preview['total']]) }}</flux:heading>
            <flux:text>{{ __('csv.review_help') }}</flux:text>
            <div class="csv-preview overflow-x-auto rounded-xl border border-zinc-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50"><tr><th class="p-3">{{ __('csv.record') }}</th><th class="p-3">{{ __('csv.opportunity') }}</th><th class="p-3">{{ __('csv.review') }}</th><th class="p-3">{{ __('csv.decision') }}</th></tr></thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr wire:key="record-{{ $row['number'] }}" class="border-t border-zinc-200 align-top">
                                <td class="p-3" data-label="{{ __('csv.record') }}">{{ $row['number'] }}</td>
                                <td class="max-w-xs p-3" data-label="{{ __('csv.opportunity') }}"><strong>{{ $row['data']['company'] ?? __('csv.missing_company') }}</strong><div>{{ $row['data']['title'] ?? '' }}</div><div class="text-zinc-500">{{ $row['data']['location'] ?? '' }}</div></td>
                                <td class="max-w-md p-3" data-label="{{ __('csv.review') }}">
                                    @if ($row['duplicate'] === 'url') <p>{{ __('csv.duplicate_url') }}</p>
                                    @elseif ($row['errors']) <p>{{ __('csv.needs_review') }}</p>
                                    @elseif ($row['duplicate'] === 'candidate') <p>{{ __('csv.duplicate_candidate') }}</p>
                                    @else <p>{{ __('csv.ready') }}</p> @endif
                                    @foreach ($row['errors'] as $error) <p class="text-red-700">{{ $error }}</p> @endforeach
                                    <details class="mt-2">
                                        <summary class="cursor-pointer">{{ __('csv.mapped_values') }}</summary>
                                        <dl class="mt-2 space-y-1">
                                            @foreach ($row['data'] as $field => $value)
                                                @continue($field === 'normalized_application_url')
                                                @if ($field === 'extra_fields')
                                                    @foreach ($value ?? [] as $extraKey => $extraValue)
                                                        <div><dt class="font-medium">{{ ucfirst(str_replace('_', ' ', $extraKey)) }}</dt><dd class="whitespace-pre-wrap break-words">{{ $extraValue }}</dd></div>
                                                    @endforeach
                                                @else
                                                    <div><dt class="font-medium">{{ $field === 'archived_at' ? __('csv.archived_at') : __('jobs.'.$field) }}</dt><dd class="whitespace-pre-wrap break-words">{{ is_bool($value) ? ($value ? __('jobs.yes') : __('jobs.no')) : ($value instanceof \BackedEnum ? $value->label() : (string) $value) }}</dd></div>
                                                @endif
                                            @endforeach
                                        </dl>
                                    </details>
                                </td>
                                <td class="p-3" data-label="{{ __('csv.decision') }}">
                                    @if ($row['duplicate'] !== 'url')
                                        <label class="flex gap-2"><input type="checkbox" wire:model="excluded" value="{{ $row['number'] }}"> {{ __('csv.exclude') }}</label>
                                        @if ($row['duplicate'] === 'candidate' && ! $row['errors'])<label class="mt-2 flex gap-2"><input type="checkbox" wire:model="includedCandidates" value="{{ $row['number'] }}"> {{ __('csv.include_candidate') }}</label>@endif
                                    @else {{ __('csv.always_skipped') }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button wire:click="$set('page', {{ max(1, $page - 1) }})" :disabled="$page <= 1">{{ __('csv.previous') }}</flux:button>
                <span>{{ __('csv.page', ['page' => max(1, min($page, $preview['pages'])), 'pages' => $preview['pages']]) }}</span>
                <flux:button wire:click="$set('page', {{ min($preview['pages'], $page + 1) }})" :disabled="$page >= $preview['pages']">{{ __('csv.next') }}</flux:button>
            </div>
            @error('import') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            @error('excluded') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <flux:button wire:click="importReviewed" variant="primary" wire:loading.attr="disabled">{{ __('csv.commit') }}</flux:button>
        </section>
    @endif

    <section class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('jobs.add_from_urls') }}</flux:heading>
        <flux:text>{{ __('jobs.add_from_urls_hint') }}</flux:text>
        <form wire:submit="addUrls" class="space-y-4">
            <flux:textarea wire:model="urls" rows="4" :placeholder="__('jobs.urls_placeholder')" :aria-label="__('jobs.add_from_urls')" />
            @error('urls') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary">{{ __('jobs.add_from_urls_submit') }}</flux:button>
        </form>
    </section>

    <section id="saved" class="space-y-4 rounded-2xl border border-zinc-200 p-5">
        <flux:heading size="lg" level="2">{{ __('jobs.saved_title') }}</flux:heading>
        <flux:text>{{ __('jobs.saved_hint') }}</flux:text>
        <form wire:submit="importSaved" class="space-y-4">
            <flux:textarea wire:model="savedText" rows="5" :placeholder="__('jobs.saved_text')" :aria-label="__('jobs.saved_text')" />
            @error('savedText') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <div><flux:input wire:model="savedUrl" type="url" :label="__('jobs.saved_url')" />@error('savedUrl') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror</div>
                <flux:select wire:model="savedJob" :label="__('jobs.saved_job')">
                    <flux:select.option value="">{{ __('jobs.saved_job_new') }}</flux:select.option>
                    @foreach ($this->jobs as $job)<flux:select.option value="{{ $job->id }}">{{ $job->company }} — {{ $job->title }}</flux:select.option>@endforeach
                </flux:select>
            </div>
            @error('savedJob') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary">{{ __('jobs.saved_submit') }}</flux:button>
        </form>
    </section>

</div>
