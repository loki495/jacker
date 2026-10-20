<?php

declare(strict_types=1);

namespace App\Services;

/** Reduces fetched HTML to its readable text, size-capped, for the AI fallback. */
final class PageText
{
    public function extract(string $html, int $maxChars): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|header|footer|form|iframe)\b.*?</\1>|<!--.*?-->#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h\d|tr|section|article)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace(["/[ \t\xC2\xA0]+/", "/ *\n\s*/"], [' ', "\n"], $text) ?? $text;

        return mb_substr(trim($text), 0, $maxChars);
    }
}
