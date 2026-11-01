<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opportunity;
use App\Support\AgentFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AgentFileController extends Controller
{
    public function __invoke(Opportunity $opportunity, string $file): BinaryFileResponse
    {
        $path = AgentFiles::path($opportunity, $file);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$file.'-'.$opportunity->id.'.pdf"']);
    }
}
