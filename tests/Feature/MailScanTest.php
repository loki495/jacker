<?php

declare(strict_types=1);

use App\Actions\ExtractJobLinks;
use App\Actions\ParseJobAlertCards;
use App\Actions\ScanMailbox;
use App\Jobs\ProcessLinkCheck;
use App\Jobs\ScanMailboxJob;
use App\Models\LinkCheck;
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

it('creates listings from LinkedIn alert cards without fetching LinkedIn, once per job', function (): void {
    Bus::fake([ProcessLinkCheck::class]);
    fakeMailbox([fakeMail('<c@x>', '', ALERT)]);
    expect(app(ScanMailbox::class)->handle())->toMatchArray(['messages' => 1, 'queued' => 2]);
    $job = Opportunity::query()->where('company', 'Acme Corp')->firstOrFail();
    expect($job->title)->toBe('Senior PHP Developer')->and($job->location)->toBe('Santa Monica, CA')->and($job->application_url)->toBe('https://www.linkedin.com/jobs/view/4469238554')
        ->and($job->extra_fields['Job alert'])->toBe('web developer in Los Angeles Metropolitan Area')->and($job->extra_fields['LinkedIn notes'])->toContain('actively hiring');
    expect(LinkCheck::query()->count())->toBe(0)->and(Opportunity::query()->count())->toBe(2);
    fakeMailbox([fakeMail('<d@x>', '', ALERT)]);
    app(ScanMailbox::class)->handle();
    expect(Opportunity::query()->count())->toBe(2);
});

it('fails clearly when the mailbox is not configured', function (): void {
    config(['mailscan.username' => null, 'mailscan.password' => null]);
    expect(fn () => (new MailboxReader)->recent())->toThrow(RuntimeException::class, __('jobs.mail_not_configured'));
    Queue::fake();
    Livewire::test('pages::queue')->call('scanMailbox')->assertSee(__('jobs.mail_not_configured'));
    Queue::assertNotPushed(ScanMailboxJob::class);
});

it('queues a mailbox scan from the log page when configured', function (): void {
    Queue::fake();
    config(['mailscan.username' => 'me@example.test', 'mailscan.password' => 'app-password']);
    Livewire::test('pages::queue')->call('scanMailbox')->assertSee(__('jobs.mail_scan_queued'));
    Queue::assertPushed(ScanMailboxJob::class);
});

it('dry run lists the links without recording emails or queueing checks', function (): void {
    Bus::fake([ProcessLinkCheck::class]);
    fakeMailbox([fakeMail('<d@x>', '', ALERT)]);
    $this->artisan('mail:scan --dry-run')->expectsOutputToContain('https://www.linkedin.com/jobs/view/4469238554')->assertSuccessful();
    expect(MailMessage::query()->count())->toBe(0)->and(LinkCheck::query()->count())->toBe(0);
});
