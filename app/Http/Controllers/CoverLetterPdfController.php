<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Services\MarkdownPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The letter's own Download PDF: same renderer (Liberation Sans, same header) as the materials section. */
final class CoverLetterPdfController extends Controller
{
    public function __invoke(Opportunity $opportunity, MarkdownPdf $renderer): Response
    {
        $letter = CoverLetter::query()->where('opportunity_id', $opportunity->id)->firstOrFail();

        try {
            $pdf = $renderer->render($letter->body, 'letter')['pdf'];
        } catch (InvalidArgumentException $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cover-letter-'.Str::slug($opportunity->company ?: 'job').'.pdf"',
        ]);
    }
}
