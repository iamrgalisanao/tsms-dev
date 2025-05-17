<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;

class PdfExportService
{
    protected $dompdf;
    
    public function __construct()
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        
        $this->dompdf = new Dompdf($options);
    }
    
    /**
     * Generate a PDF from log data
     *
     * @param Collection $logs The logs to include in the PDF
     * @param array $filters The filters applied to generate the logs
     * @param string $filename The filename for the PDF
     * @return string The path to the generated PDF file
     */
    public function generateLogsPdf(Collection $logs, array $filters, string $filename = null)
    {
        try {
            // Generate a filename if one wasn't provided
            if (!$filename) {
                $filename = 'logs_export_' . date('Y-m-d_H-i-s') . '.pdf';
            }
            
            // Generate the HTML content
            $html = View::make('exports.logs-pdf', [
                'logs' => $logs,
                'filters' => $filters,
                'generated_at' => now()->format('Y-m-d H:i:s'),
                'total_logs' => $logs->count()
            ])->render();
            
            // Load the HTML into DOMPDF
            $this->dompdf->loadHtml($html);
            
            // Set paper size and orientation (landscape may be better for wide tables)
            $this->dompdf->setPaper('A4', 'landscape');
            
            // Render the PDF
            $this->dompdf->render();
            
            // Output the generated PDF to the specified file
            $output = $this->dompdf->output();
            $path = storage_path('app/public/exports/' . $filename);
            
            // Ensure the directory exists
            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            
            // Save the file
            file_put_contents($path, $output);
            
            return $path;
        } catch (\Exception $e) {
            Log::error('Error generating PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
