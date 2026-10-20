<?php

declare(strict_types=1);

namespace App\Actions;

/** Reads LinkedIn job-alert digests: each card is title, company, location, optional notes, then "View job: URL". */
final class ParseJobAlertCards
{
    private const string NOTE = '/^(this company is actively hiring|apply with resume|easy apply|be an early applicant|promoted|reposted|\d+ (school alum|connection)|.*\b(school alum|alumni|connections?) (work|works)?\b.*$)/i';

    /** @return list<array{url: string, title: string, company: string, location: string, notes: list<string>, alert: ?string}> */
    public function handle(string $text): array
    {
        $alert = preg_match('/^Your job alert for (.+?)\s*$/mi', $text, $match) === 1 ? trim($match[1]) : null;
        $cards = [];
        foreach (preg_split('/^\s*-{10,}\s*$/m', $text) ?: [] as $block) {
            if (preg_match('#^\s*View job:\s*(https?://\S+)#mi', $block, $link) !== 1) {
                continue;
            }
            $before = substr($block, 0, (int) strpos($block, 'View job:'));
            $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/', $before) ?: []), fn (string $line): bool => $line !== '' && ! str_contains($line, 'http')));
            $notes = array_values(array_filter($lines, fn (string $line): bool => preg_match(self::NOTE, $line) === 1));
            $lines = array_values(array_diff($lines, $notes));
            if (count($lines) < 3) {
                continue;
            }
            [$title, $company, $location] = array_slice($lines, -3);
            $url = $this->canonical($link[1]);
            if ($url !== null) {
                $cards[] = ['url' => $url, 'title' => $title, 'company' => $company, 'location' => $location, 'notes' => $notes, 'alert' => $alert];
            }
        }

        return $cards;
    }

    private function canonical(string $url): ?string
    {
        return preg_match('#^https?://(?:www\.)?linkedin\.com/(?:comm/)?jobs/view/(\d+)#i', $url, $m) === 1 ? 'https://www.linkedin.com/jobs/view/'.$m[1] : null;
    }
}
