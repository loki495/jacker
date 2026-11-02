<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Services\MarkdownPdf;
use App\Support\AgentFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves one version (generated, edited, general) of a job's resume or letter inline, or as a download with ?download=1. */
final class MaterialPdfController extends Controller
{
    public function __invoke(Request $request, Opportunity $opportunity, string $kind, string $version): Response|BinaryFileResponse
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $name = $kind.'-'.$version.'-'.$opportunity->id.'.pdf';
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => $disposition.'; filename="'.$name.'"'];

        $path = match ($version) {
            'generated' => AgentFiles::path($opportunity, $kind),
            'general' => $kind === 'resume' ? AgentFiles::generalResume() : null,
            default => null,
        };
        if ($version !== 'edited') {
            abort_unless(is_string($path) && is_file($path) && ! is_link($path), 404);

            return response()->file($path, $headers);
        }

        $body = ($kind === 'resume'
            ? Resume::query()->where('opportunity_id', $opportunity->id)->first()?->body
            : CoverLetter::query()->where('opportunity_id', $opportunity->id)->first()?->body) ?? abort(404);
        try {
            $rendered = (new MarkdownPdf)->render($body, $kind);
        } catch (InvalidArgumentException $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($rendered['pdf'], 200, $headers);
    }
}
