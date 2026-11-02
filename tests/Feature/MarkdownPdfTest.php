<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\MarkdownPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;

uses(RefreshDatabase::class);

function pdfText(string $pdf): string
{
    return (new Parser)->parseContent($pdf)->getText();
}

const RESUME_MD = <<<'MD'
## Experience

**Senior Developer**, Acme *2020-2024*

- Built **Laravel** services
- Led a team of 4

---

[Portfolio](https://example.com/me)
MD;

it('renders a resume with Liberation Sans, a text layer and a page count', function (): void {
    Setting::query()->create(['key' => 'profile.name', 'value' => ['v' => 'Ada Lovelace']]);
    $result = app(MarkdownPdf::class)->render(RESUME_MD, 'resume');

    expect(substr($result['pdf'], 0, 4))->toBe('%PDF')->and($result['pages'])->toBe(1);
    $text = pdfText($result['pdf']);
    expect($text)->toContain('Ada Lovelace')->toContain('EXPERIENCE')->toContain('Built Laravel services')->toContain('Portfolio');
    preg_match_all('#/BaseFont\s*/(?:[A-Z]{6}\+)?([A-Za-z-]+)#', $result['pdf'], $fonts);
    expect($fonts[1])->not->toBeEmpty();
    foreach ($fonts[1] as $font) {
        expect($font)->toStartWith('LiberationSans');
    }
});

it('renders a letter with the same fonts and no banned words', function (): void {
    $result = app(MarkdownPdf::class)->render("Dear team,\n\nI like *your* product.\n\nThanks,\nAda", 'letter');

    expect(pdfText($result['pdf']))->toContain('Dear team')->not->toMatch('/\bCV\b|References/i');
    expect($result['pages'])->toBe(1)->and($result['pdf'])->toContain('LiberationSans');
});

it('rejects the words CV and References instead of rewriting the text', function (string $text): void {
    expect(fn () => app(MarkdownPdf::class)->render($text, 'resume'))->toThrow(InvalidArgumentException::class, 'not allowed');
})->with(['## References', 'Attached is my cv.', 'Send me your CV']);

it('rejects empty and overlong input', function (): void {
    expect(fn () => app(MarkdownPdf::class)->render("  \n ", 'letter'))->toThrow(InvalidArgumentException::class, 'empty');
    expect(fn () => app(MarkdownPdf::class)->render(str_repeat('a', MarkdownPdf::MAX_CHARS + 1), 'letter'))->toThrow(InvalidArgumentException::class, 'too long');
});

it('escapes raw HTML and drops images and unsafe links', function (): void {
    $md = "<script>alert('x')</script> and <b>raw</b>\n\n![pic](http://example.com/a.png) [bad](javascript:alert(1))";
    $text = pdfText(app(MarkdownPdf::class)->render($md, 'letter')['pdf']);

    expect($text)->toContain('<script>')->toContain('<b>raw</b>')->not->toContain('pic')->and($text)->toContain('bad');
});

it('reports more than one page for a long resume', function (): void {
    $md = "## Work\n\n".implode("\n", array_map(fn (int $i): string => "- Bullet number $i with some words", range(1, 150)));

    expect(app(MarkdownPdf::class)->render($md, 'resume')['pages'])->toBeGreaterThan(1);
});
