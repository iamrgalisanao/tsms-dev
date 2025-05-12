<?php

namespace App\Services\Security\Contracts;

interface SecurityReportingInterface
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
    ): int;

    /**
     * Get security report by ID
     * 
     * @param int $reportId
     * @param int $tenantId
     * @return array|null
     */
    public function getReport(int $reportId, int $tenantId): ?array;

    /**
     * Get security reports list for a tenant
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getReportsList(int $tenantId, array $filters = []): array;
    
    /**
     * Create a report template
     * 
     * @param int $tenantId
     * @param array $templateData
     * @return int Template ID
     */
    public function createReportTemplate(int $tenantId, array $templateData): int;
    
    /**
     * Get template by ID
     * 
     * @param int $templateId
     * @param int $tenantId
     * @return array|null
     */
    public function getReportTemplate(int $templateId, int $tenantId): ?array;
    
    /**
     * Get templates list for a tenant
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getReportTemplates(int $tenantId, array $filters = []): array;
}