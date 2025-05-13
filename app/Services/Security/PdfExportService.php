<?php

namespace App\Services\Security;

use App\Exceptions\SecurityReportExportException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class PdfExportService
{
    public function generate(string $filename, array $reportData): array
    {
        try {
            // Set memory limit for large reports
            $currentLimit = ini_get('memory_limit');
            ini_set('memory_limit', '512M');

            // Configure PDF options
            $pdf = Pdf::loadView('reports.security.pdf', ['report' => $reportData]);
            $pdf->setOption('isRemoteEnabled', true);
            $pdf->setOption('isUnicode', true);
            $pdf->setOption('isFontSubsettingEnabled', true);
            $pdf->setOption('isHtml5ParserEnabled', true);
            $pdf->setPaper('a4', 'portrait');

            // Generate PDF with proper error handling
            try {
                $content = $pdf->output();
                if (empty($content) || !str_starts_with($content, '%PDF')) {
                    throw new SecurityReportExportException('Generated content is not a valid PDF');
                }
            } catch (\Exception $e) {
                throw new SecurityReportExportException(
                    'Failed to generate PDF content: ' . $e->getMessage()
                );
            }

            $mime = 'application/pdf';
            
            // Reset memory limit
            ini_set('memory_limit', $currentLimit);

            return ['content' => $content, 'filename' => $filename, 'mime' => $mime];

        } catch (\Exception $e) {
            Log::error('PDF generation failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_peak_usage(true),
                'report_data_size' => strlen(json_encode($reportData))
            ]);

            return $this->generateErrorPdf($e, $filename);
        }
    }

    private function generateErrorPdf(\Exception $e, string $filename): array
    {
        // Generate a basic PDF with error message
        $errorContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n" .
            "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n" .
            "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/Resources <<\n/Font <<\n/F1 4 0 R\n>>\n>>\n" .
            "/MediaBox [0 0 612 792]\n/Contents 5 0 R\n>>\nendobj\n" .
            "4 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n>>\nendobj\n" .
            "5 0 obj\n<<\n/Length 68\n>>\nstream\nBT\n/F1 12 Tf\n72 720 Td\n" .
            "(PDF generation failed: " . addslashes($e->getMessage()) . ") Tj\nET\nendstream\nendobj\n" .
            "xref\n0 6\n0000000000 65535 f\n0000000010 00000 n\n0000000056 00000 n\n" .
            "0000000111 00000 n\n0000000212 00000 n\n0000000277 00000 n\ntrailer\n" .
            "<<\n/Size 6\n/Root 1 0 R\n>>\nstartxref\n399\n%%EOF\n";

        return [
            'content' => $errorContent,
            'filename' => $filename,
            'mime' => 'application/pdf'
        ];
    }
}