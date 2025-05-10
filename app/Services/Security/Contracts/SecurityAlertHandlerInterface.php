<?php

namespace App\Services\Security\Contracts;

interface SecurityAlertHandlerInterface
{
    /**
     * Handle a security alert that has been triggered
     *
     * @param int $ruleId The ID of the alert rule that was triggered
     * @param array $eventData The event data that triggered the alert
     * @return void
     */
    public function handleAlert(int $ruleId, array $eventData): void;

    /**
     * Send a notification for a security alert
     *
     * @param int $ruleId The ID of the alert rule
     * @param array $eventData The event data
     * @param array $channels The notification channels to use
     * @return void
     */
    public function sendNotification(int $ruleId, array $eventData, array $channels): void;

    /**
     * Log an alert for audit purposes
     *
     * @param int $ruleId The ID of the alert rule
     * @param array $eventData The event data
     * @return void
     */
    public function logAlert(int $ruleId, array $eventData): void;
}