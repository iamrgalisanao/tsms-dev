<?php

namespace App\Http\Controllers;

use App\Exports\LogExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Barryvdh\DomPDF\Facade\Pdf;

class LogExportController extends Controller
{
    public function export(Request $request, string $format)
    {
        $fileName = 'logs_' . date('Y-m-d_His');
        $export = new LogExport($request);

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($export) {
                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF");
                fputcsv($output, $export->headings());

                foreach ($export->collection() as $log) {
                    fputcsv($output, $export->row($log));
                }

                fclose($output);
            }, $fileName . '.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if ($format === 'pdf') {
            $logs = $export->collection();
            $filters = $request->only(['type', 'severity', 'date_from', 'date_to']);
            $pdf = PDF::loadView('exports.logs-pdf', compact('logs', 'filters'));
            return $pdf->download($fileName . '.pdf');
        }

        abort(Response::HTTP_BAD_REQUEST, 'Invalid export format');
    }
}
