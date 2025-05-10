<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Services\Security\Contracts\LoginSecurityMonitorInterface;
use App\Services\Security\Contracts\SecurityMonitorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LoginSecurityMonitorService implements LoginSecurityMonitorInterface
{
    private SecurityMonitorInterface $securityMonitor;
    private int $maxAttempts;
    private int $decayMinutes;

    public function __construct(
        SecurityMonitorInterface $securityMonitor,
        int $maxAttempts = 5,
        int $decayMinutes = 15
    ) {
        $this->securityMonitor = $securityMonitor;
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    public function recordFailedLogin(string $email, string $sourceIp, array $context = []): void
    {
        // Record the failed attempt in cache
        $ipKey = $this->getIpKey($sourceIp);
        $emailKey = $this->getEmailKey($email);
        
        Cache::put(
            $ipKey,
            Cache::get($ipKey, 0) + 1,
            now()->addMinutes($this->decayMinutes)
        );

        Cache::put(
            $emailKey,
            Cache::get($emailKey, 0) + 1,
            now()->addMinutes($this->decayMinutes)
        );

        // Record security event
        $this->securityMonitor->recordEvent(
            'failed_login',
            'warning',
            array_merge($context, ['email' => $email]),
            $sourceIp
        );

        // Check if we should block
        if ($this->shouldBlock($email, $sourceIp)) {
            $this->securityMonitor->recordEvent(
                'login_blocked',
                'error',
                array_merge($context, [
                    'email' => $email,
                    'reason' => 'too_many_attempts'
                ]),
                $sourceIp
            );
        }
    }

    public function recordSuccessfulLogin(int $userId, string $sourceIp, array $context = []): void
    {
        // Clear any failed attempt records
        if (isset($context['email'])) {
            Cache::forget($this->getEmailKey($context['email']));
        }
        Cache::forget($this->getIpKey($sourceIp));

        // Record successful login event
        $this->securityMonitor->recordEvent(
            'successful_login',
            'info',
            $context,
            $sourceIp,
            $userId
        );
    }

    public function isBlocked(string $email, string $sourceIp): bool
    {
        return $this->shouldBlock($email, $sourceIp);
    }

    private function shouldBlock(string $email, string $sourceIp): bool
    {
        $ipAttempts = Cache::get($this->getIpKey($sourceIp), 0);
        $emailAttempts = Cache::get($this->getEmailKey($email), 0);

        return $ipAttempts >= $this->maxAttempts || $emailAttempts >= $this->maxAttempts;
    }

    private function getIpKey(string $ip): string
    {
        return "login_attempts:ip:{$ip}";
    }

    private function getEmailKey(string $email): string
    {
        return "login_attempts:email:{$email}";
    }
}
