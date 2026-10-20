<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MailMessage;
use App\Models\Opportunity;
use App\Services\MailboxReader;
use App\Support\ApplicationUrl;
use Illuminate\Validation\ValidationException;

final readonly class ScanMailbox
{
    public function __construct(private MailboxReader $reader, private ExtractJobLinks $extract, private ParseJobAlertCards $cards, private SaveOpportunity $save, private QueueLinkChecks $queue) {}

    /**
     * With $dryRun the mailbox is read but nothing is recorded or queued; the URLs that would be queued are returned.
     *
     * @return array{messages: int, found: int, queued: int, urls: list<string>}
     */
    public function handle(bool $dryRun = false): array
    {
        $totals = ['messages' => 0, 'found' => 0, 'queued' => 0, 'urls' => []];
        foreach ($this->reader->recent() as $message) {
            if (MailMessage::query()->where('message_id', $message['id'])->exists()) {
                continue;
            }
            $cards = array_values(array_filter($this->cards->handle($message['text']), fn (array $card): bool => $this->isNew($card['url'])));
            $cardUrls = array_column($cards, 'url');
            $links = array_values(array_unique([...$cardUrls, ...$this->extract->handle($message['html'], $message['text'])]));
            $fresh = array_values(array_filter($links, fn (string $url): bool => ! str_contains($url, '//www.linkedin.com/') && $this->isNew($url)));
            if ($dryRun) {
                $totals['urls'] = array_values(array_unique([...$totals['urls'], ...$fresh, ...array_map(fn (array $card): string => "{$card['url']}  {$card['title']} | {$card['company']} | {$card['location']}", $cards)]));
                $totals['queued'] += count($fresh) + count($cards);
                $totals['messages']++;
                $totals['found'] += count($links);

                continue;
            }
            $queued = ($fresh === [] ? 0 : $this->queue->forUrls($fresh)['queued']) + $this->createListings($cards);
            MailMessage::query()->create([
                'message_id' => $message['id'], 'sender' => $message['sender'], 'subject' => $message['subject'], 'received_at' => $message['received_at'],
                'links_found' => count($links), 'links_queued' => $queued,
            ]);
            $totals['messages']++;
            $totals['found'] += count($links);
            $totals['queued'] += $queued;
        }

        return $totals;
    }

    private function isNew(string $url): bool
    {
        return ! Opportunity::query()->where('normalized_application_url', ApplicationUrl::normalize($url))->exists();
    }

    /**
     * @param  list<array{url: string, title: string, company: string, location: string, notes: list<string>, alert: ?string}>  $cards
     */
    private function createListings(array $cards): int
    {
        $created = 0;
        foreach ($cards as $card) {
            $extra = array_filter(['Job alert' => $card['alert'], 'LinkedIn notes' => implode('; ', $card['notes'])]);
            try {
                $this->save->handle([
                    'title' => $card['title'], 'company' => $card['company'], 'location' => $card['location'], 'application_url' => $card['url'],
                    'source' => __('jobs.mail_source'), 'extra_fields' => $extra,
                ]);
                $created++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $created;
    }
}
