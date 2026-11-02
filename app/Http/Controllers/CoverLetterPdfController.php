<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Support\CoverLetterSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class CoverLetterPdfController extends Controller
{
    public function __invoke(Opportunity $opportunity): Response
    {
        $letter = CoverLetter::query()->where('opportunity_id', $opportunity->id)->firstOrFail();
        $contact = CoverLetterSettings::contact();

        return Pdf::loadView('pdf.cover-letter', ['letter' => $letter, 'contact' => $contact, 'lines' => CoverLetterSettings::headerLines()])
            ->download('cover-letter-'.Str::slug($opportunity->company ?: 'job').'.pdf');
    }
}
