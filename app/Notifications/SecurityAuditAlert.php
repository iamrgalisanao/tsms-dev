<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityAuditAlert extends Notification implements ShouldQueue
{
    use Queueable;

    private array $auditData;
    private string $alertType;

    public function __construct(string $alertType, array $auditData)
    {
        $this->alertType = $alertType;
        $this->auditData = $auditData;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->getSubjectForAlertType();
        $message = $this->getMessageForAlertType();

        return (new MailMessage)
                    ->subject($subject)
                    ->priority('high')
                    ->line($message)
                    ->line("Alert Type: {$this->alertType}")
                    ->when(isset($this->auditData['user_id']), function ($mail) {
                        $mail->line("User ID: {$this->auditData['user_id']}");
                    })
                    ->when(isset($this->auditData['ip_address']), function ($mail) {
                        $mail->line("IP Address: {$this->auditData['ip_address']}");
                    })
                    ->when(isset($this->auditData['user_agent']), function ($mail) {
                        $mail->line("User Agent: {$this->auditData['user_agent']}");
                    })
                    ->when(isset($this->auditData['details']), function ($mail) {
                        if (is_array($this->auditData['details'])) {
                            $mail->line("Details:");
                            foreach ($this->auditData['details'] as $key => $value) {
                                $mail->line("- {$key}: {$value}");
                            }
                        } else {
                            $mail->line("Details: {$this->auditData['details']}");
                        }
                    })
                    ->action('View Security Dashboard', url('/security/audit'))
                    ->line('Please investigate this security event immediately.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'security_audit_alert',
            'alert_type' => $this->alertType,
            'title' => $this->getSubjectForAlertType(),
            'message' => $this->getMessageForAlertType(),
            'audit_data' => $this->auditData,
            'severity' => $this->getSeverityForAlertType(),
            'created_at' => now(),
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function getSubjectForAlertType(): string
    {
        return match ($this->alertType) {
            'suspicious_login' => 'TSMS Security Alert: Suspicious Login Attempt',
            'multiple_failed_logins' => 'TSMS Security Alert: Multiple Failed Login Attempts',
            'unauthorized_access' => 'TSMS Security Alert: Unauthorized Access Attempt',
            'data_modification' => 'TSMS Security Alert: Suspicious Data Modification',
            'api_abuse' => 'TSMS Security Alert: API Abuse Detected',
            'privilege_escalation' => 'TSMS Security Alert: Privilege Escalation Attempt',
            default => 'TSMS Security Alert: Security Event Detected',
        };
    }

    private function getMessageForAlertType(): string
    {
        return match ($this->alertType) {
            'suspicious_login' => 'A suspicious login attempt has been detected.',
            'multiple_failed_logins' => 'Multiple failed login attempts detected from the same source.',
            'unauthorized_access' => 'An unauthorized access attempt has been detected.',
            'data_modification' => 'Suspicious data modification activity detected.',
            'api_abuse' => 'API abuse or unusual usage patterns detected.',
            'privilege_escalation' => 'An attempt to escalate privileges has been detected.',
            default => 'A security event requiring attention has been detected.',
        };
    }

    private function getSeverityForAlertType(): string
    {
        return match ($this->alertType) {
            'suspicious_login', 'multiple_failed_logins' => 'medium',
            'unauthorized_access', 'privilege_escalation' => 'high',
            'data_modification', 'api_abuse' => 'high',
            default => 'medium',
        };
    }
}
