<?php

declare(strict_types=1);

use App\Actions\CsvImport;
use App\Actions\MarkMaterialsUsed;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Support\AgentFiles;
use App\Support\CsvMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/jacker-used-'.bin2hex(random_bytes(4));
    app()->useStoragePath($this->scratch);
    $this->job = Opportunity::factory()->create(['company' => 'Acme Co', 'status' => 'saved']);
    foreach (['resume', 'letter'] as $kind) {
        File::ensureDirectoryExists(dirname(AgentFiles::path($this->job, $kind)));
        file_put_contents(AgentFiles::path($this->job, $kind), '%PDF-1.4 generated '.$kind);
    }
});

afterEach(fn () => File::deleteDirectory($this->scratch));

function frozen(Opportunity $job, string $kind): string
{
    return file_get_contents(MarkMaterialsUsed::directory($job, $job->refresh()->materials_used_at).'/'.$kind.'.pdf');
}

it('freezes the exact chosen PDFs, records the choice and leaves the status alone', function (): void {
    Resume::query()->create(['opportunity_id' => $this->job->id, 'body' => "## Skills\n- PHP"]);
    $job = app(MarkMaterialsUsed::class)->handle($this->job, 'edited', 'generated');

    expect($job->resume_choice)->toBe('edited')->and($job->letter_choice)->toBe('generated')->and($job->materials_used_at)->not->toBeNull()
        ->and($job->status->value)->toBe('saved')->and($job->has_applied)->toBeFalse()
        ->and(str_starts_with(frozen($job, 'resume'), '%PDF'))->toBeTrue()
        ->and(frozen($job, 'letter'))->toBe('%PDF-1.4 generated letter');
});

it('does not change the frozen copy when the sources are edited or regenerated later', function (): void {
    Resume::query()->create(['opportunity_id' => $this->job->id, 'body' => '## One']);
    $job = app(MarkMaterialsUsed::class)->handle($this->job, 'edited', 'generated');
    $resume = frozen($job, 'resume');
    $letter = frozen($job, 'letter');

    Resume::query()->where('opportunity_id', $job->id)->update(['body' => '## Two, quite different']);
    file_put_contents(AgentFiles::path($job, 'letter'), '%PDF-1.4 regenerated');
    unlink(AgentFiles::path($job, 'resume'));

    expect(frozen($job, 'resume'))->toBe($resume)->and(frozen($job, 'letter'))->toBe($letter);
    $this->get(route('opportunities.used', [$job, 'letter']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(file_get_contents($this->get(route('opportunities.used', [$job, 'letter']))->baseResponse->getFile()->getPathname()))->toBe($letter);
});

it('keeps earlier frozen folders when marked again and records the latest', function (): void {
    $first = app(MarkMaterialsUsed::class)->handle($this->job, 'generated', 'generated');
    $firstDir = MarkMaterialsUsed::directory($first, $first->materials_used_at);
    $firstAt = $first->materials_used_at->copy();
    $this->travel(2)->seconds();
    $second = app(MarkMaterialsUsed::class)->handle($first, 'generated', 'none');
    expect(is_dir($firstDir))->toBeTrue()->and($second->letter_choice)->toBeNull()->and($second->materials_used_at->gt($firstAt))->toBeTrue()
        ->and(is_file(MarkMaterialsUsed::directory($second, $second->materials_used_at).'/letter.pdf'))->toBeFalse();
});

it('refuses a second mark in the same second instead of overwriting the frozen copy', function (): void {
    $this->travelTo(now()->startOfSecond());
    app(MarkMaterialsUsed::class)->handle($this->job, 'generated', 'generated');
    expect(fn () => app(MarkMaterialsUsed::class)->handle($this->job, 'generated', 'none'))->toThrow(ValidationException::class, 'a moment ago');
    expect($this->job->refresh()->letter_choice)->toBe('generated');
});

it('freezes the general resume', function (): void {
    File::ensureDirectoryExists($this->scratch.'/app/agent/general');
    file_put_contents($this->scratch.'/app/agent/general/resume.pdf', '%PDF general');
    $job = app(MarkMaterialsUsed::class)->handle($this->job, 'general', 'none');
    expect(frozen($job, 'resume'))->toBe('%PDF general')->and($job->letter_choice)->toBeNull();
});

it('saves nothing when a source is missing, the renderer rejects it or nothing is picked', function (): void {
    $attempt = fn (string $resume, string $letter) => fn () => app(MarkMaterialsUsed::class)->handle($this->job, $resume, $letter);
    expect($attempt('edited', 'generated'))->toThrow(ValidationException::class);
    expect($attempt('general', 'none'))->toThrow(ValidationException::class);
    expect($attempt('none', 'none'))->toThrow(ValidationException::class);
    CoverLetter::query()->create(['opportunity_id' => $this->job->id, 'body' => 'See my references']);
    expect($attempt('generated', 'edited'))->toThrow(ValidationException::class, 'not allowed');
    $job = $this->job->refresh();
    expect($job->materials_used_at)->toBeNull()->and($job->resume_choice)->toBeNull()
        ->and(File::exists($this->scratch.'/app/agent/apply/'.$job->id.'/used'))->toBeFalse();
});

it('rolls the frozen folder back when recording fails', function (): void {
    Opportunity::saving(fn () => throw new RuntimeException('db down'));
    expect(fn () => app(MarkMaterialsUsed::class)->handle($this->job, 'generated', 'generated'))->toThrow(RuntimeException::class);
    expect(glob($this->scratch.'/app/agent/apply/'.$this->job->id.'/used/*'))->toBe([]);
});

it('marks as used from the detail page and shows the frozen record', function (): void {
    Livewire::test('pages::detail', ['opportunity' => $this->job])
        ->assertSet('resumeVersion', 'generated')->assertSet('letterVersion', 'generated')
        ->call('markUsed')->assertSee(__('materials.used_done'))->assertSee(__('materials.used_open_resume'));
    expect($this->job->refresh()->resume_choice)->toBe('generated');
    Livewire::test('pages::detail', ['opportunity' => $this->job])->set('resumeVersion', 'edited')->call('markUsed')->assertHasErrors(['used']);
    expect($this->job->refresh()->resume_choice)->toBe('generated');
    $this->get('/opportunities/'.$this->job->id)->assertOk();
});

it('404s the frozen copy before anything is marked', function (): void {
    $this->get(route('opportunities.used', [$this->job, 'resume']))->assertNotFound();
});

it('exports the choice columns and ignores them on import', function (): void {
    app(MarkMaterialsUsed::class)->handle($this->job, 'generated', 'generated');
    $csv = $this->get('/export')->streamedContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", $csv)));
    $header = $lines[0];
    expect($header)->toContain('resume_choice', 'letter_choice', 'materials_used_at');
    $row = array_combine($header, $lines[1]);
    expect($row['resume_choice'])->toBe('generated')->and($row['materials_used_at'])->not->toBe('');

    Storage::fake('local');
    Storage::disk('local')->put('in.csv', "company,resume_choice,letter_choice,materials_used_at\nNew Co,edited,edited,2026-01-01T00:00:00+00:00\n");
    $path = Storage::disk('local')->path('in.csv');
    $mapping = app(CsvMapping::class)->suggest(['company', 'resume_choice', 'letter_choice', 'materials_used_at']);
    expect($mapping)->toBe([0 => 'company', 1 => '', 2 => '', 3 => '']);
    app(CsvImport::class)->handle($path, $mapping);
    $new = Opportunity::query()->where('company', 'New Co')->firstOrFail();
    expect($new->resume_choice)->toBeNull()->and($new->materials_used_at)->toBeNull()->and($new->extra_fields)->toBe([]);
});
