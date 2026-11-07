<?php

declare(strict_types=1);

use App\Actions\ExtractJobLinks;
use App\Actions\ParseJobAlertCards;
use App\Actions\ScanMailbox;
use App\Jobs\ProcessLinkCheck;
use App\Jobs\ScanMailboxJob;
use App\Models\LinkCheck;
use App\Models\LinkedInLink;
use App\Models\MailMessage;
use App\Models\Opportunity;
use App\Services\MailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeMailbox(array $messages): void
{
    app()->instance(MailboxReader::class, new class($messages) extends MailboxReader
    {
        public function __construct(private readonly array $messages) {}

        public function recent(): array
        {
            return $this->messages;
        }
    });
}

function fakeMail(string $id, string $html, string $text = ''): array
{
    return ['id' => $id, 'sender' => 'jobs@alerts.test', 'subject' => 'New jobs', 'received_at' => now(), 'html' => $html, 'text' => $text];
}

it('keeps only known job-site links, strips tracking and dedupes', function (): void {
    $html = '<a href="https://www.linkedin.com/jobs/view/123456/?trackingId=abc&amp;refId=x">One</a>'
        .'<a href="https://www.linkedin.com/comm/jobs/view/123456?other=1">Again</a>'
        .'<a href="https://www.indeed.com/viewjob?jk=ab12&from=email&tk=zz">Two</a>'
        .'<a href="https://jobs.lever.co/acme/0b2f6a3e-1111-2222-3333-444455556666?lever-source=x">Three</a>'
        .'<a href="https://example.com/unsubscribe">Unsub</a><a href="javascript:alert(1)">x</a>';
    expect((new ExtractJobLinks)->handle($html, 'plain https://boards.greenhouse.io/acme/jobs/998877?gh_src=1'))->toBe([
        'https://www.linkedin.com/jobs/view/123456', 'https://www.indeed.com/viewjob?jk=ab12',
        'https://jobs.lever.co/acme/0b2f6a3e-1111-2222-3333-444455556666', 'https://boards.greenhouse.io/acme/jobs/998877',
    ]);
});

it('queues new links once, skips known jobs and already scanned emails, and stores no body', function (): void {
    Bus::fake([ProcessLinkCheck::class]);
    Opportunity::factory()->create(['application_url' => 'https://boards.greenhouse.io/acme/jobs/111', 'normalized_application_url' => 'https://boards.greenhouse.io/acme/jobs/111']);
    fakeMailbox([
        fakeMail('<a@x>', '<a href="https://boards.greenhouse.io/acme/jobs/111">Known</a><a href="https://boards.greenhouse.io/acme/jobs/222">New</a>'),
        fakeMail('<b@x>', 'no links here'),
    ]);
    expect(app(ScanMailbox::class)->handle())->toMatchArray(['messages' => 2, 'found' => 2, 'queued' => 1])
        ->and(LinkCheck::query()->pluck('url')->all())->toBe(['https://boards.greenhouse.io/acme/jobs/222'])
        ->and(MailMessage::query()->count())->toBe(2);
    expect(app(ScanMailbox::class)->handle())->toMatchArray(['messages' => 0, 'found' => 0, 'queued' => 0]);
});

const ALERT = <<<'TXT'
Your job alert for web developer in Los Angeles Metropolitan Area
New jobs match your preferences.

Senior PHP Developer
Acme Corp
Santa Monica, CA

This company is actively hiring
12 school alumni work here
View job: https://www.linkedin.com/comm/jobs/view/4469238554/?trackingId=abc&refId=x

---------------------------------------------------------

Web Developer
RemoteHunter
United States
Apply with resume & profile
View job: https://www.linkedin.com/comm/jobs/view/4467874490/?trackingId=def

---------------------------------------------------------

View all jobs: https://www.linkedin.com/comm/jobs/search-results/?keywords=x
TXT;

it('parses alert cards into title, company, location, notes and the alert name', function (): void {
    $cards = (new ParseJobAlertCards)->handle(ALERT);
    expect($cards)->toHaveCount(2)->and($cards[0])->toBe([
        'url' => 'https://www.linkedin.com/jobs/view/4469238554', 'title' => 'Senior PHP Developer', 'company' => 'Acme Corp', 'location' => 'Santa Monica, CA',
        'notes' => ['This company is actively hiring', '12 school alumni work here'], 'alert' => 'web developer in Los Angeles Metropolitan Area',
    ])->and($cards[1]['title'])->toBe('Web Developer')->and($cards[1]['notes'])->toBe(['Apply with resume & profile']);
    expect((new ParseJobAlertCards)->handle("Just a chat message\nView job: https://www.linkedin.com/jobs/view/1"))->toBe([]);
});

it('keeps LinkedIn alert cards as links to read later, without fetching LinkedIn or creating listings', function (): void {
    Bus::fake([ProcessLinkCheck::class]);
    fakeMailbox([fakeMail('<c@x>', '', ALERT)]);
    expect(app(ScanMailbox::class)->handle())->toMatchArray(['messages' => 1, 'queued' => 0, 'saved' => 2]);
    $link = LinkedInLink::query()->where('company', 'Acme Corp')->firstOrFail();
    expect($link->title)->toBe('Senior PHP Developer')->and($link->location)->toBe('Santa Monica, CA')
        ->and($link->url)->toBe('https://www.linkedin.com/jobs/view/4469238554')->and($link->job_id)->toBe('4469238554')
        ->and($link->alert)->toBe('web developer in Los Angeles Metropolitan Area')->and($link->notes)->toContain('This company is actively hiring')
        ->and($link->status)->toBe(LinkedInLink::NEW);
    expect(LinkCheck::query()->count())->toBe(0)->and(Opportunity::query()->count())->toBe(0);
    fakeMailbox([fakeMail('<d@x>', '', ALERT)]);
    app(ScanMailbox::class)->handle();
    expect(LinkedInLink::query()->count())->toBe(2);
});

it('exports the kept LinkedIn links as JSON for a later scan', function (): void {
    LinkedInLink::query()->create([
        'job_id' => '4469238554', 'url' => 'https://www.linkedin.com/jobs/view/4469238554',
        'title' => 'Senior PHP Developer', 'company' => 'Acme Corp', 'status' => LinkedInLink::NEW,
    ]);

    $this->artisan('linkedin:links --json')
        ->expectsOutputToContain('"url":"https://www.linkedin.com/jobs/view/4469238554"')
        ->assertSuccessful();
});

it('offers mailbox scanning on Mail leads instead of Queue and redirects old bookmarks', function (): void {
    $this->get('/mail-leads')->assertOk()
        ->assertSee('<h1>Mail leads</h1>', false)
        ->assertSee('wire:click="scanMailbox"', false)
        ->assertSee(__('jobs.mail_scan'))
        ->assertSee('href="'.route('mail-leads').'"', false);

    $this->get('/queue?kind=links')->assertOk()->assertDontSee('wire:click="scanMailbox"', false);
    $this->get('/linkedin')->assertRedirect('/mail-leads');
});

it('fails clearly when the mailbox is not configured', function (): void {
    config(['mailscan.username' => null, 'mailscan.password' => null]);
    expect(fn () => (new MailboxReader)->recent())->toThrow(RuntimeException::class, __('jobs.mail_not_configured'));
    Queue::fake();
    Livewire::test('pages::linkedin')->call('scanMailbox')->assertSee(__('jobs.mail_not_configured'));
    Queue::assertNotPushed(ScanMailboxJob::class);
});

it('queues a mailbox scan from Mail leads when configured', function (): void {
    Queue::fake();
    config(['mailscan.username' => 'me@example.test', 'mailscan.password' => 'app-password']);
    Livewire::test('pages::linkedin')->call('scanMailbox')->assertSee(__('jobs.mail_scan_queued'));
    Queue::assertPushed(ScanMailboxJob::class);
});

it('dry run lists the links without recording emails or queueing checks', function (): void {
    Bus::fake([ProcessLinkCheck::class]);
    fakeMailbox([fakeMail('<d@x>', '', ALERT)]);
    $this->artisan('mail:scan --dry-run')->expectsOutputToContain('https://www.linkedin.com/jobs/view/4469238554')->assertSuccessful();
    expect(MailMessage::query()->count())->toBe(0)->and(LinkCheck::query()->count())->toBe(0)->and(LinkedInLink::query()->count())->toBe(0);
});

it('shows the sign-in banner and retries failed links', function (): void {
    $link = LinkedInLink::query()->create(['job_id' => '1', 'url' => 'https://www.linkedin.com/jobs/view/1/', 'status' => LinkedInLink::FAILED, 'scan_error' => 'LinkedIn asked for a sign-in: run bin/linkedin-scan --login']);
    Livewire::test('pages::linkedin')->assertSee(__('jobs.linkedin_needs_sign_in'))->call('retryFailed')->assertDontSee(__('jobs.linkedin_needs_sign_in'));
    expect($link->refresh()->status)->toBe(LinkedInLink::QUEUED);
});
