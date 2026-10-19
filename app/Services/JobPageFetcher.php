<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;

final readonly class JobPageFetcher
{
    public function __construct(private UrlGuard $guard) {}

    /**
     * Follows redirects by hand so every hop is checked against the guard.
     *
     * @return array{status: int, body: string, url: string}
     */
    public function fetch(string $url): array
    {
        $maxBytes = (int) config('linkcheck.max_bytes');
        $connected = null;
        for ($hop = 0; $hop <= (int) config('linkcheck.max_redirects'); $hop++) {
            $addresses = $this->guard->assertPublic($url);
            $response = Http::withoutRedirecting()
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions([
                    'decode_content' => false,
                    'on_stats' => function (TransferStats $stats) use (&$connected): void {
                        $connected = $stats->getHandlerStat('primary_ip');
                    },
                    'curl' => [CURLOPT_RESOLVE => [$this->pin($url, $addresses)]],
                    'progress' => fn (int $total, int $downloaded) => $downloaded > $maxBytes ? throw new \RuntimeException(__('jobs.link_too_large')) : null,
                ])
                ->timeout((int) config('linkcheck.timeout'))
                ->withUserAgent((string) config('linkcheck.user_agent'))
                ->accept('text/html,application/xhtml+xml')
                ->get($url);
            $this->assertConnectedToVetted(is_string($connected) ? $connected : null, $addresses);
            $location = $response->header('Location');
            if ($response->redirect() && $location !== '') {
                $url = $this->absolute($url, $location);

                continue;
            }

            return ['status' => $response->status(), 'body' => $response->body(), 'url' => $url];
        }

        throw new \RuntimeException(__('jobs.link_too_many_redirects'));
    }

    /**
     * Pins the connection to an address the guard vetted so the HTTP client cannot resolve the host again.
     * Built from the same normalised scheme, host and port the client uses, so the entry cannot miss.
     *
     * @param  list<string>  $addresses
     */
    private function pin(string $url, array $addresses): string
    {
        $uri = new Uri($url);
        $port = $uri->getPort() ?? ($uri->getScheme() === 'http' ? 80 : 443);
        usort($addresses, fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return trim($uri->getHost(), '[]').':'.$port.':'.$address;
    }

    /**
     * Defence in depth: if the client still connected somewhere the guard did not vet, discard the page.
     *
     * @param  list<string>  $addresses
     */
    private function assertConnectedToVetted(?string $connected, array $addresses): void
    {
        if ($connected === null || $connected === '') {
            return;
        }
        $packed = @inet_pton($connected);
        foreach ($addresses as $address) {
            if ($packed !== false && @inet_pton($address) === $packed) {
                return;
            }
        }

        throw new \RuntimeException(__('jobs.link_unsafe_address'));
    }

    private function absolute(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
