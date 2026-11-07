<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CoverLetterSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Renders the Markdown subset of a resume or cover letter (headings, bold, italic, bullets, links, rules) to an
 * ATS-clean PDF: Liberation Sans, real text layer. Input that would break the style rules is rejected, never altered.
 */
final class MarkdownPdf
{
    public const int MAX_CHARS = 20000;

    public const array KINDS = ['resume', 'letter'];

    /** The word CV and a References section are banned from application PDFs; matched as whole words, any case. */
    private const string BANNED = '/\b(?:cv|references?)\b/iu';

    /**
     * @param  'resume'|'letter'  $kind
     * @return array{pdf: string, pages: int}
     *
     * @throws InvalidArgumentException when the Markdown is empty, over MAX_CHARS, or contains a banned word
     */
    public function render(string $markdown, string $kind): array
    {
        $markdown = trim(str_replace("\r\n", "\n", $markdown));
        if ($markdown === '') {
            throw new InvalidArgumentException(__('pdf.empty'));
        }
        if (mb_strlen($markdown) > self::MAX_CHARS) {
            throw new InvalidArgumentException(__('pdf.too_long', ['max' => number_format(self::MAX_CHARS)]));
        }
        if (preg_match(self::BANNED, $markdown, $found) === 1) {
            throw new InvalidArgumentException(__('pdf.banned', ['word' => $found[0]]));
        }

        // Dompdf reads its font cache path from config, which can differ from storage_path() (tests move storage to a temp dir).
        File::ensureDirectoryExists((string) config('dompdf.options.font_dir', storage_path('fonts')));
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = (string) preg_replace('/<img\b[^>]*>/i', '', $html);

        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'Liberation Sans'])
            ->loadView('pdf.markdown', [
                'kind' => $kind,
                'html' => $html,
                'name' => CoverLetterSettings::contact()['name'] ?? null,
                'lines' => CoverLetterSettings::headerLines(),
                'fontDir' => resource_path('fonts'),
            ]);
        $output = $pdf->output();

        return ['pdf' => $output, 'pages' => $pdf->getDomPDF()->getCanvas()->get_page_count()];
    }
}
