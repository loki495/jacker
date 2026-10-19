<?php

declare(strict_types=1);

namespace App\Services;

/** Reads schema.org JobPosting JSON-LD from a page; returns only the opportunity fields it finds. */
final class JobPostingParser
{
    private const array PERIODS = ['HOUR' => 'hour', 'DAY' => 'day', 'WEEK' => 'week', 'MONTH' => 'month', 'YEAR' => 'year'];

    private const array TYPES = ['FULL_TIME' => 'full_time', 'PART_TIME' => 'part_time', 'CONTRACTOR' => 'contract', 'TEMPORARY' => 'temporary', 'INTERN' => 'internship'];

    /** @return array<string, mixed> */
    public function parse(string $html): array
    {
        $posting = $this->findPosting($html);
        if ($posting === null) {
            return [];
        }
        $org = is_array($posting['hiringOrganization'] ?? null) ? $posting['hiringOrganization'] : [];
        $fields = [
            'title' => $this->text($posting['title'] ?? null),
            'company' => $this->text($org['name'] ?? (is_string($posting['hiringOrganization'] ?? null) ? $posting['hiringOrganization'] : null)),
            'company_url' => $this->text($org['sameAs'] ?? $org['url'] ?? null),
            'location' => $this->location($posting['jobLocation'] ?? null),
            'benefits' => $this->text($posting['jobBenefits'] ?? null),
            'responsibilities' => $this->text($posting['responsibilities'] ?? null),
            'requirements' => $this->join([$posting['qualifications'] ?? null, $posting['skills'] ?? null, $posting['experienceRequirements'] ?? null]),
            'hours' => $this->text($posting['workHours'] ?? null),
        ];
        if (strtoupper((string) ($posting['jobLocationType'] ?? '')) === 'TELECOMMUTE') {
            $fields['work_arrangement'] = 'remote';
        }
        $type = $posting['employmentType'] ?? null;
        $type = strtoupper((string) (is_array($type) ? ($type[0] ?? '') : $type));
        if (isset(self::TYPES[$type])) {
            $fields['employment_type'] = self::TYPES[$type];
        }
        $fields += $this->salary($posting['baseSalary'] ?? null);

        return array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed>|null */
    private function findPosting(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $raw) {
            $json = json_decode(html_entity_decode(trim($raw)), true);
            $found = is_array($json) ? $this->search($json) : null;
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null
     */
    private function search(array $node): ?array
    {
        $type = $node['@type'] ?? null;
        if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
            return $node;
        }
        foreach ($node as $child) {
            if (is_array($child) && ($found = $this->search($child)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace(['#</(li|p|div|h\d)>|<br\s*/?>#i'], "\n", $value) ?? $value;
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
        $value = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n", $value) ?? $value) ?? $value);

        return $value === '' ? null : $value;
    }

    /** @param list<mixed> $values */
    private function join(array $values): ?string
    {
        $parts = array_filter(array_map($this->text(...), $values));

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function location(mixed $location): ?string
    {
        if (! is_array($location)) {
            return null;
        }
        $places = array_is_list($location) ? $location : [$location];
        $names = [];
        foreach ($places as $place) {
            $address = is_array($place) && is_array($place['address'] ?? null) ? $place['address'] : [];
            $country = $address['addressCountry'] ?? null;
            $country = is_array($country) ? ($country['name'] ?? null) : $country;
            $line = implode(', ', array_filter([$this->text($address['addressLocality'] ?? null), $this->text($address['addressRegion'] ?? null), $this->text($country)]));
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return $names === [] ? null : implode('; ', array_unique($names));
    }

    /** @return array<string, mixed> */
    private function salary(mixed $salary): array
    {
        $value = is_array($salary) && is_array($salary['value'] ?? null) ? $salary['value'] : null;
        if ($value === null) {
            return [];
        }
        $min = $value['minValue'] ?? $value['value'] ?? null;
        $max = $value['maxValue'] ?? $value['value'] ?? null;
        $out = [];
        if (is_numeric($min)) {
            $out['salary_min'] = (string) $min;
        }
        if (is_numeric($max)) {
            $out['salary_max'] = (string) $max;
        }
        if ($out === []) {
            return [];
        }
        $currency = strtoupper((string) ($salary['currency'] ?? ''));
        if (preg_match('/^[A-Z]{3}$/', $currency)) {
            $out['currency'] = $currency;
        }
        $period = self::PERIODS[strtoupper((string) ($value['unitText'] ?? ''))] ?? null;
        if ($period !== null) {
            $out['pay_period'] = $period;
        }

        return $out;
    }
}
