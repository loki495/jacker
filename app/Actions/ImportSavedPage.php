<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\JobPostingParser;
use App\Services\PageText;
use InvalidArgumentException;

final readonly class ImportSavedPage
{
    public function __construct(
        private JobPostingParser $parser,
        private PageText $text,
        private RunLinkCheck $apply,
        private RequestAiExtraction $ai,
    ) {}

    /**
     * Reads a saved page (HTML or MHTML) or pasted text without any network request: structured job data is applied
     * at once, anything else is kept as page text and queued for the AI step. Fills the target job's empty fields, or creates a listing.
     */
    public function handle(string $content, ?string $url = null, ?Opportunity $target = null): LinkCheck
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException(__('jobs.saved_empty'));
        }
        $content = mb_substr($this->decodeMhtml($content), 0, (int) config('linkcheck.max_bytes'));
        $isHtml = preg_match('/<(html|body|head|div|p|script)\b/i', $content) === 1;
        $fields = $isHtml ? $this->parser->parse($content) : [];
        $check = LinkCheck::query()->create([
            'kind' => LinkCheck::KIND_SAVED, 'url' => $url ?? '', 'opportunity_id' => $target?->id,
            'status' => LinkCheckStatus::Running, 'started_at' => now(),
        ]);
        if ($fields !== []) {
            $this->apply->applyFields($check, $fields);

            return $check->refresh();
        }
        $check->page_text = mb_substr($isHtml ? $this->text->extract($content, (int) config('linkcheck.max_text_chars')) : $content, 0, (int) config('linkcheck.max_text_chars'));
        $check->fill(['status' => LinkCheckStatus::Done, 'result' => LinkResult::Ok, 'message' => __('jobs.saved_queued_for_ai'), 'finished_at' => now()])->save();
        $this->ai->handle($check);

        return $check->refresh();
    }

    /** Returns the first text/html part of an MHTML archive, decoded; any other content unchanged. */
    private function decodeMhtml(string $content): string
    {
        if (preg_match('/^(?:From:|MIME-Version:|Content-Type:\s*multipart\/related)/mi', substr($content, 0, 2000)) !== 1 || preg_match('/boundary="?([^";\r\n]+)"?/i', $content, $boundary) !== 1) {
            return $content;
        }
        foreach (explode('--'.$boundary[1], $content) as $part) {
            if (preg_match('/^\s*Content-Type:\s*text\/html/mi', $part) !== 1) {
                continue;
            }
            [$headers, $body] = array_pad(preg_split('/\R\R/', ltrim($part), 2) ?: [], 2, '');
            $encoding = preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $headers, $enc) === 1 ? strtolower($enc[1]) : '';

            return match ($encoding) {
                'quoted-printable' => quoted_printable_decode($body),
                'base64' => base64_decode($body, false) ?: $body,
                default => $body,
            };
        }

        return $content;
    }
}
