<?php

declare(strict_types=1);

namespace App\Actions;

final class ExtractJobLinks
{
    /** @return list<string> Distinct posting URLs from known job sites, stripped to the part that identifies the posting. */
    public function handle(string $html, string $text): array
    {
        preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $hrefs);
        preg_match_all('#https?://[^\s<>"\')\]]+#i', $text.' '.strip_tags($html), $plain);
        $links = [];
        foreach (array_merge($hrefs[1], $plain[0]) as $raw) {
            $url = $this->clean(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5));
            if ($url !== null) {
                $links[$url] = $url;
            }
        }

        return array_values($links);
    }

    private function clean(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        foreach ((array) config('mailscan.job_links') as $pattern => $keep) {
            if (! preg_match($pattern, $host.$path)) {
                continue;
            }
            parse_str($parts['query'] ?? '', $query);
            $kept = array_intersect_key($query, array_flip($keep));

            $path = preg_replace('#^/comm/#', '/', $path) ?? $path;

            return 'https://'.$host.rtrim($path, '/').($kept === [] ? '' : '?'.http_build_query($kept));
        }

        return null;
    }
}
