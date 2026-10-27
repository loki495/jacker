<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Support\CoverLetterSettings;
use App\Support\ProfileLinks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class CoverLetterPdfController extends Controller
{
    public function __invoke(Opportunity $opportunity): Response
    {
        $letter = CoverLetter::query()->where('opportunity_id', $opportunity->id)->firstOrFail();
        $contact = CoverLetterSettings::contact();
        $lines = array_values(array_filter([$contact['email'] ?? null, $contact['phone'] ?? null, $contact['location'] ?? null, ...array_values(ProfileLinks::all())]));

        return Pdf::loadView('pdf.cover-letter', ['letter' => $letter, 'contact' => $contact, 'lines' => $lines])
            ->download('cover-letter-'.Str::slug($opportunity->company ?: 'job').'.pdf');
    }
}
