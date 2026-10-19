<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use InvalidArgumentException;

/** Refuses URLs that would make the server call itself or the home network. */
final readonly class UrlGuard
{
    /** @param (Closure(string): list<string>)|null $resolver */
    public function __construct(private ?Closure $resolver = null) {}

    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(__('jobs.link_unsafe_scheme'));
        }
        // ponytail: resolves once before the request, so DNS rebinding between check and fetch is not covered
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver ? ($this->resolver)($host) : (gethostbynamel($host) ?: []));
        if ($addresses === []) {
            throw new InvalidArgumentException(__('jobs.link_unresolvable'));
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException(__('jobs.link_unsafe_address'));
            }
        }
    }
}
