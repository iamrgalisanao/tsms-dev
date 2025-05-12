<?php

namespace App\Services\Security;

use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use App\Models\SecurityEvent;
use App\Services\Security\Contracts\SecurityReportingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SecurityReportingService implements SecurityReportingInterface
{
    /**
     * Generate a new security report
     * 
     * @param int $tenantId
     * @param array $filters
     * @param string $format
     * @param int|null $templateId
     * @param int|null $userId
     * @return int Report ID
     */
    public function generateReport(
        int $tenantId, 
        array $filters, 
        string $format = 'html', 
        ?int $templateId = null, 
        ?int $userId = null
    ): int {
        try {
            // Get the current authenticated user ID if not provided
            $userId = $userId ?? Auth::id();
            
            // Create the report record
            $report = SecurityReport::create([
                'tenant_id' => $tenantId,
                'security_report_template_id' => $templateId,
                'name' => $filters['name'] ?? 'Security Report ' . date('Y-m-d H:i:s'),
                'status' => 'generating',
                'filters' => $filters,
                'generated_by' => $userId,
                'from_date' => $filters['from'] ?? null,
                'to_date' => $filters['to'] ?? null,
                'format' => $format
            ]);
            
            // Generate the report data
            $reportData = $this->generateReportData($tenantId, $filters);
            
            // Update the report with the results
            $report->update([
                'status' => 'completed',
                'results' => $reportData
            ]);
            
            return $report->id;
            
        } catch (\Exception $e) {
            // Log the error
            Log::error('Failed to generate security report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId,
                'filters' => $filters
            ]);
            
            // Create a failed report record if possible
            if (isset($report)) {
                $report->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
                
                return $report->id;
            }
            
            // Rethrow the exception
            throw $e;
        }
    }

    /**
     * Get security report by ID
     * 
     * @param int $reportId
     * @param int $tenantId
     * @return array|null
     */
    public function getReport(int $reportId, int $tenantId): ?array
    {
        $report = SecurityReport::where('id', $reportId)
            ->where('tenant_id', $tenantId)
            ->first();
            
        if (!$report) {
            return null;
        }
        
        return $report->toArray();
    }

    /**
     * Get security reports list for a tenant
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getReportsList(int $tenantId, array $filters = []): array
    {
        $query = SecurityReport::where('tenant_id', $tenantId);
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (isset($filters['format'])) {
            $query->where('format', $filters['format']);
        }
        
        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }
        
        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }
        
        return $query->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
    
    /**
     * Create a report template
     * 
     * @param int $tenantId
     * @param array $templateData
     * @return int Template ID
     */
    public function createReportTemplate(int $tenantId, array $templateData): int
    {
        try {
            $template = SecurityReportTemplate::create([
                'tenant_id' => $tenantId,
                'name' => $templateData['name'],
                'description' => $templateData['description'] ?? null,
                'filters' => $templateData['filters'] ?? null,
                'type' => $templateData['type'],
                'columns' => $templateData['columns'] ?? null,
                'format' => $templateData['format'] ?? 'html',
                'is_scheduled' => $templateData['is_scheduled'] ?? false,
                'schedule_frequency' => $templateData['schedule_frequency'] ?? null,
                'notification_settings' => $templateData['notification_settings'] ?? null,
                'is_system' => $templateData['is_system'] ?? false
            ]);
            
            return $template->id;
            
        } catch (\Exception $e) {
            Log::error('Failed to create security report template', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get template by ID
     * 
     * @param int $templateId
     * @param int $tenantId
     * @return array|null
     */
    public function getReportTemplate(int $templateId, int $tenantId): ?array
    {
        $template = SecurityReportTemplate::where('id', $templateId)
            ->where('tenant_id', $tenantId)
            ->first();
            
        if (!$template) {
            return null;
        }
        
        return $template->toArray();
    }
    
    /**
     * Get templates list for a tenant
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getReportTemplates(int $tenantId, array $filters = []): array
    {
        $query = SecurityReportTemplate::where('tenant_id', $tenantId);
        
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        if (isset($filters['is_scheduled'])) {
            $query->where('is_scheduled', $filters['is_scheduled']);
        }
        
        if (isset($filters['is_system'])) {
            $query->where('is_system', $filters['is_system']);
        }
        
        return $query->orderBy('name')
            ->get()
            ->toArray();
    }
    
    /**
     * Generate the actual report data
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    private function generateReportData(int $tenantId, array $filters = []): array
    {
        // Build query for security events
        $query = SecurityEvent::where('tenant_id', $tenantId);
        
        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        
        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        
        if (isset($filters['from'])) {
            $query->where('event_timestamp', '>=', $filters['from']);
        }
        
        if (isset($filters['to'])) {
            $query->where('event_timestamp', '<=', $filters['to']);
        }
        
        if (isset($filters['source_ip'])) {
            $query->where('source_ip', $filters['source_ip']);
        }
        
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        // Get the events
        $events = $query->orderBy('event_timestamp', 'desc')
            ->limit(1000) // Limit to avoid memory issues
            ->get();
        
        // Prepare the report data with summary statistics
        $reportData = [
            'total_events' => $events->count(),
            'events_by_type' => $events->groupBy('event_type')
                ->map(function ($items) {
                    return $items->count();
                }),
            'events_by_severity' => $events->groupBy('severity')
                ->map(function ($items) {
                    return $items->count();
                }),
            'events_list' => $events->map(function ($event) {
                // Format event data for report
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                    'timestamp' => $event->event_timestamp,
                    'source_ip' => $event->source_ip,
                    'user_id' => $event->user_id,
                    'context' => $event->context,
                ];
            }),
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters
        ];
        
        return $reportData;
    }
}
