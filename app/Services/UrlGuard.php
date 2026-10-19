<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Refuses URLs that would make the server call itself or the home network. */
final readonly class UrlGuard
{
    /** Special-purpose ranges the PHP private/reserved filter flags miss: CGNAT, benchmarking, NAT64, 6to4, site-local, multicast. */
    private const array EXTRA_BLOCKED = ['100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4', '64:ff9b::/96', '2002::/16', 'fec0::/10', 'ff00::/8'];

    /** @param (Closure(string): list<string>)|null $resolver */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * Throws unless every address the host resolves to (A and AAAA) is public; returns those addresses so
     * the caller can pin the connection to them instead of resolving again.
     *
     * @return list<string>
     */
    public function assertPublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(__('jobs.link_unsafe_scheme'));
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver ? ($this->resolver)($host) : $this->resolve($host));
        if ($addresses === []) {
            throw new InvalidArgumentException(__('jobs.link_unresolvable'));
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new InvalidArgumentException(__('jobs.link_unsafe_address'));
            }
        }

        return $addresses;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_map(fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''), $records);

        return array_values(array_unique(array_filter($addresses)));
    }

    private function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged by its IPv4 part
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $address = (string) inet_ntop(substr($packed, 12));
            $packed = substr($packed, 12);
        }
        if (IpUtils::checkIp($address, self::EXTRA_BLOCKED)) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
