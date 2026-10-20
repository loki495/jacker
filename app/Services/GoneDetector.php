<?php

declare(strict_types=1);

namespace App\Services;

/** Spots pages that answer 200 but say the job is gone: an error phrase near the top of the text, or an expired validThrough. */
final readonly class GoneDetector
{
    public function __construct(private JobPostingParser $parser) {}

    public function detect(string $html, string $text): ?string
    {
        if ($this->parser->expired($html)) {
            return __('jobs.link_expired');
        }
        $head = mb_substr($text, 0, (int) config('linkcheck.gone_window'));
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $match) === 1 ? html_entity_decode(trim($match[1])) : '';
        foreach ((array) config('linkcheck.gone_patterns') as $pattern) {
            if (preg_match($pattern, $head) === 1 || preg_match($pattern, $title) === 1) {
                return __('jobs.link_gone_text');
            }
        }

        return null;
    }
}
