<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MarkMaterialsUsed;
use App\Models\Opportunity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the frozen PDF copy recorded by "Mark as used", exactly as it was saved. */
final class UsedMaterialController extends Controller
{
    public function __invoke(Opportunity $opportunity, string $kind): BinaryFileResponse
    {
        abort_unless($opportunity->materials_used_at !== null, 404);
        $path = MarkMaterialsUsed::directory($opportunity, $opportunity->materials_used_at).'/'.$kind.'.pdf';
        abort_unless(is_file($path) && ! is_link($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="used-'.$kind.'-'.$opportunity->id.'.pdf"']);
    }
}
