<?php

namespace App\Services\Security\Contracts;

interface LoginSecurityMonitorInterface
{
    /**
     * Record a failed login attempt
     *
     * @param string $email The email that was used
     * @param string $sourceIp The source IP address
     * @param array $context Additional context information
     * @return void
     */
    public function recordFailedLogin(string $email, string $sourceIp, array $context = []): void;

    /**
     * Record a successful login
     *
     * @param int $userId The ID of the user who logged in
     * @param string $sourceIp The source IP address
     * @param array $context Additional context information
     * @return void
     */
    public function recordSuccessfulLogin(int $userId, string $sourceIp, array $context = []): void;

    /**
     * Check if the IP address or email is currently blocked
     *
     * @param string $email The email to check
     * @param string $sourceIp The IP address to check
     * @return bool
     */
    public function isBlocked(string $email, string $sourceIp): bool;
}
