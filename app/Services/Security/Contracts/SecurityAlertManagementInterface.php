<?php

namespace App\Services\Security\Contracts;

interface SecurityAlertManagementInterface
{
    /**
     * Get security alerts list
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getAlerts(int $tenantId, array $filters = []): array;

    /**
     * Get a specific alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @return array|null
     */
    public function getAlert(int $alertId, int $tenantId): ?array;

    /**
     * Acknowledge an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string|null $notes
     * @return bool
     */
    public function acknowledgeAlert(int $alertId, int $tenantId, int $userId, ?string $notes = null): bool;

    /**
     * Resolve an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string $status
     * @param string|null $notes
     * @return bool
     */
    public function resolveAlert(int $alertId, int $tenantId, int $userId, string $status, ?string $notes = null): bool;

    /**
     * Add notes to an alert
     * 
     * @param int $alertId
     * @param int $tenantId
     * @param int $userId
     * @param string $notes
     * @return bool
     */
    public function addAlertNotes(int $alertId, int $tenantId, int $userId, string $notes): bool;
}
