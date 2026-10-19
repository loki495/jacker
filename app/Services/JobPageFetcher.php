<?php

declare(strict_types=1);

namespace App\Services;

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
        for ($hop = 0; $hop <= (int) config('linkcheck.max_redirects'); $hop++) {
            $addresses = $this->guard->assertPublic($url);
            $response = Http::withoutRedirecting()
                ->withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => [$this->pin($url, $addresses)]]])
                ->timeout((int) config('linkcheck.timeout'))
                ->withUserAgent((string) config('linkcheck.user_agent'))
                ->accept('text/html,application/xhtml+xml')
                ->get($url);
            $location = $response->header('Location');
            if ($response->redirect() && $location !== '') {
                $url = $this->absolute($url, $location);

                continue;
            }

            return ['status' => $response->status(), 'body' => $response->toPsrResponse()->getBody()->read((int) config('linkcheck.max_bytes')), 'url' => $url];
        }

        throw new \RuntimeException(__('jobs.link_too_many_redirects'));
    }

    /**
     * Pins the connection to an address the guard vetted so the HTTP client cannot resolve the host again.
     *
     * @param  list<string>  $addresses
     */
    private function pin(string $url, array $addresses): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);

        return trim((string) $parts['host'], '[]').':'.$port.':'.$addresses[0];
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
