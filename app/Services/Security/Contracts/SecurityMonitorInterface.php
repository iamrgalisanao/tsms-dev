<?php

namespace App\Services\Security\Contracts;

interface SecurityMonitorInterface
{
    /**
     * Record a security event
     *
     * @param string $eventType Type of security event
     * @param string $severity Event severity level (info, warning, error, critical)
     * @param array $context Additional context information
     * @param string|null $sourceIp Source IP address
     * @param int|null $userId Associated user ID
     * @return void
     */
    public function recordEvent(
        string $eventType,
        string $severity,
        array $context = [],
        ?string $sourceIp = null,
        ?int $userId = null
    ): void;

    /**
     * Get events for a specific tenant
     *
     * @param int $tenantId
     * @param array $filters Optional filters (type, severity, timeframe)
     * @return array
     */
    public function getEvents(int $tenantId, array $filters = []): array;

    /**
     * Check if any security rules have been triggered
     *
     * @param int $tenantId
     * @param string $eventType
     * @return bool
     */
    public function checkAlertRules(int $tenantId, string $eventType): bool;
}
