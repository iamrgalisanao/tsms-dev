<?php

namespace App\Http\Controllers;

use App\Exports\LogExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Barryvdh\DomPDF\Facade\Pdf;

class LogExportController extends Controller
{
    public function export(Request $request, string $format)
    {
        $fileName = 'logs_' . date('Y-m-d_His');

        if ($format === 'csv') {
            return Excel::download(new LogExport($request), $fileName . '.csv', \Maatwebsite\Excel\Excel::CSV);
        }

        if ($format === 'pdf') {
            $logs = (new LogExport($request))->collection();
            $pdf = PDF::loadView('exports.logs-pdf', compact('logs'));
            return $pdf->download($fileName . '.pdf');
        }

        abort(Response::HTTP_BAD_REQUEST, 'Invalid export format');
    }
}