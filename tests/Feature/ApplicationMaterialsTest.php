<?php

declare(strict_types=1);

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Support\AgentFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/jacker-materials-'.bin2hex(random_bytes(4));
    app()->useStoragePath($this->scratch);
    $this->job = Opportunity::factory()->create(['company' => 'Acme Co']);
});

afterEach(fn () => File::deleteDirectory($this->scratch));

function putPdf(string $path): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, '%PDF-1.4 fake');
}

it('saves, replaces and removes the edited resume and shows the section with both tabs', function (): void {
    $page = Livewire::test('pages::detail', ['opportunity' => $this->job])
        ->assertSee(__('materials.heading'))->assertSee(__('materials.tab_letter'))
        ->set('resumeBody', "## Experience\n- Built **things**")->call('saveResume')->assertHasNoErrors()->assertSet('resumeVersion', 'edited');
    expect(Resume::query()->count())->toBe(1);
    $page->set('resumeBody', 'Second')->call('saveResume');
    expect(Resume::query()->firstOrFail()->body)->toBe('Second');
    $page->set('resumeBody', '  ')->call('saveResume')->assertSet('resumeVersion', 'none');
    expect(Resume::query()->count())->toBe(0);
});

it('rejects an over-long resume and warns when the saved text cannot be rendered', function (): void {
    Livewire::test('pages::detail', ['opportunity' => $this->job])
        ->set('resumeBody', str_repeat('x', 20_001))->call('saveResume')->assertHasErrors(['resumeBody']);
    expect(Resume::query()->count())->toBe(0);
    Livewire::test('pages::detail', ['opportunity' => $this->job])
        ->set('resumeBody', "## References\nAvailable")->call('saveResume')
        ->assertSee(__('materials.render_problem', ['message' => __('pdf.banned', ['word' => 'References'])]));
    expect(Resume::query()->count())->toBe(1);
});

it('switches tabs and saves the letter through the same section', function (): void {
    Livewire::test('pages::detail', ['opportunity' => $this->job])->set('tab', 'letter')->assertSee(__('letter.body'))
        ->set('letterBody', 'Dear team')->call('saveLetter')->assertSet('letterVersion', 'edited');
    expect(CoverLetter::query()->firstOrFail()->body)->toBe('Dear team');
});

it('offers only the versions that exist, preferring edited, then generated, then general', function (): void {
    $page = fn () => Livewire::test('pages::detail', ['opportunity' => $this->job]);
    $page()->assertSet('resumeVersion', 'none')->assertSet('letterVersion', 'none');
    putPdf(AgentFiles::generalResume() ?? storage_path('app/agent/general/resume.pdf'));
    $page()->assertSet('resumeVersion', 'general')->assertSet('letterVersion', 'none')->assertSee(__('materials.version_general'));
    putPdf(AgentFiles::path($this->job, 'resume'));
    putPdf(AgentFiles::path($this->job, 'letter'));
    $page()->assertSet('resumeVersion', 'generated')->assertSet('letterVersion', 'generated');
    Resume::query()->create(['opportunity_id' => $this->job->id, 'body' => 'x']);
    expect($page()->instance()->versions('resume'))->toBe(['edited', 'generated', 'general']);
});

it('serves generated, general and edited PDFs inline or as a download', function (): void {
    putPdf(AgentFiles::path($this->job, 'resume'));
    putPdf(storage_path('app/agent/general/resume.pdf'));
    Resume::query()->create(['opportunity_id' => $this->job->id, 'body' => "## Skills\n- PHP"]);
    CoverLetter::query()->create(['opportunity_id' => $this->job->id, 'body' => "Dear team,\n\nHello."]);

    $this->get(route('opportunities.material', [$this->job, 'resume', 'generated']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $general = $this->get(route('opportunities.material', [$this->job, 'resume', 'general', 'download' => 1]))->assertOk();
    expect($general->headers->get('Content-Disposition'))->toStartWith('attachment');
    foreach (['resume', 'letter'] as $kind) {
        $edited = $this->get(route('opportunities.material', [$this->job, $kind, 'edited']))->assertOk();
        expect(str_starts_with($edited->getContent(), '%PDF'))->toBeTrue()->and($edited->headers->get('Content-Disposition'))->toStartWith('inline');
    }
});

it('404s for missing versions, a general letter and unknown names, and explains an unrenderable edit with 422', function (): void {
    $this->get(route('opportunities.material', [$this->job, 'resume', 'generated']))->assertNotFound();
    $this->get(route('opportunities.material', [$this->job, 'resume', 'general']))->assertNotFound();
    $this->get(route('opportunities.material', [$this->job, 'resume', 'edited']))->assertNotFound();
    putPdf(storage_path('app/agent/general/resume.pdf'));
    $this->get('/opportunities/'.$this->job->id.'/materials/letter/general')->assertNotFound();
    $this->get('/opportunities/'.$this->job->id.'/materials/cv/edited')->assertNotFound();
    CoverLetter::query()->create(['opportunity_id' => $this->job->id, 'body' => 'See my references']);
    $this->get(route('opportunities.material', [$this->job, 'letter', 'edited']))->assertStatus(422)->assertSee('which is not allowed in application PDFs');
});
