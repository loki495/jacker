<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CsvExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExportController extends Controller
{
    public function __invoke(CsvExport $export): StreamedResponse
    {
        return $export->handle();
    }
}
