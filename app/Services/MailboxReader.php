<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

/** Read-only IMAP access: fetches with BODY.PEEK, so nothing is marked read, moved or deleted. */
class MailboxReader
{
    /** @return list<array{id: string, sender: ?string, subject: ?string, received_at: ?\DateTimeInterface, html: string, text: string}> */
    public function recent(): array
    {
        if (blank(config('mailscan.username')) || blank(config('mailscan.password'))) {
            throw new RuntimeException(__('jobs.mail_not_configured'));
        }
        $client = (new ClientManager)->make([
            'host' => config('mailscan.host'), 'port' => config('mailscan.port'), 'encryption' => 'ssl', 'validate_cert' => true,
            'username' => config('mailscan.username'), 'password' => config('mailscan.password'), 'protocol' => 'imap',
        ]);
        $client->connect();
        $messages = $client->getFolder((string) config('mailscan.folder'))?->query()->since(now()->subDays((int) config('mailscan.days')))->leaveUnread()->limit((int) config('mailscan.max_messages'))->get() ?? collect();
        $out = [];
        foreach ($messages as $message) {
            $id = (string) $message->getMessageId();
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id, 'sender' => $message->getFrom()->first()?->mail, 'subject' => (string) $message->getSubject(),
                'received_at' => $message->getDate()->first()?->toDate(), 'html' => (string) $message->getHTMLBody(), 'text' => (string) $message->getTextBody(),
            ];
        }
        $client->disconnect();

        return $out;
    }
}
