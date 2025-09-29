<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Services\NotificationService;

class SendThresholdTestEmail extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tsms:send-threshold-test-email
        {--to= : Destination email address (overrides notifications.admin_emails)}
        {--terminal-id= : Existing terminal ID to use (otherwise a test terminal is created)}
        {--count=3 : Number of INVALID transactions to create}
        {--threshold=2 : Threshold for triggering notification}
        {--time-window=60 : Time window in minutes for threshold check}
        {--mailer= : Force a specific mailer for this run (e.g., log, smtp)}';

    /**
     * The console command description.
     */
    protected $description = 'Trigger a live TransactionFailureThresholdExceeded notification via NotificationService for a real mail send (uses current mailer)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $to = $this->option('to');
        $terminalId = $this->option('terminal-id');
    $count = (int) $this->option('count');
        $threshold = (int) $this->option('threshold');
        $timeWindow = (int) $this->option('time-window');
    $forceMailer = $this->option('mailer');

        if ($to) {
            config(['notifications.admin_emails' => [$to]]);
            $this->info("Using recipient: {$to}");
        }

        // Prefer current mailer; allow override; if none configured locally, fallback to log driver to visualize
        $mailer = $forceMailer ?: config('mail.default');
        if (!$mailer) {
            $mailer = 'log';
        }
        config(['mail.default' => $mailer]);
        $this->info("Mailer in use: {$mailer}");
        if ($mailer === 'smtp') {
            $this->line(sprintf(
                'SMTP -> host=%s port=%s username=%s encryption=%s from=%s',
                config('mail.mailers.smtp.host'),
                config('mail.mailers.smtp.port'),
                config('mail.mailers.smtp.username'),
                config('mail.mailers.smtp.encryption'),
                config('mail.from.address')
            ));
        }

        // Configure thresholds for this run
        config([
            'notifications.transaction_failure_threshold' => $threshold,
            'notifications.transaction_failure_time_window' => $timeWindow,
        ]);

        // Resolve or create a terminal
        if (!$terminalId) {
            $company = Company::first() ?: Company::create([
                'customer_code' => 'CUST-' . Str::upper(Str::random(8)),
                'company_name' => 'Test Co',
                'tin' => (string) random_int(10000000000, 99999999999),
            ]);

            $tenant = Tenant::first() ?: Tenant::create([
                'company_id' => $company->id,
                'trade_name' => 'CLI Test Tenant',
                'status' => 'Operational',
            ]);

            $terminal = PosTerminal::create([
                'tenant_id' => $tenant->id,
                'serial_number' => 'TERM-' . Str::upper(Str::random(10)),
                'status_id' => 1,
            ]);
            $terminalId = $terminal->id;
            $this->info("Created test terminal ID: {$terminalId}");
        } else {
            $this->info("Using existing terminal ID: {$terminalId}");
        }

        // Create INVALID transactions to exceed threshold
        for ($i = 0; $i < $count; $i++) {
            Transaction::create([
                'tenant_id' => PosTerminal::find($terminalId)->tenant_id,
                'terminal_id' => $terminalId,
                'transaction_id' => 'FAIL-CLI-' . $i . '-' . Str::upper(Str::random(6)),
                'hardware_id' => 'HW-' . Str::upper(Str::random(8)),
                'transaction_timestamp' => now()->subMinutes($i * 5),
                'base_amount' => 100 + $i,
                'customer_code' => optional(PosTerminal::find($terminalId)->tenant->company)->customer_code ?? ('CUST-' . Str::upper(Str::random(6))),
                'payload_checksum' => Str::random(32),
                'validation_status' => 'INVALID',
                'created_at' => now()->subMinutes($i * 5),
            ]);
        }

        // Trigger notification (with error reporting)
        try {
            app(NotificationService::class)->checkTransactionFailureThresholds((string) $terminalId);
        } catch (\Throwable $e) {
            $this->error('Error while sending notification: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }

        $this->info('Notification triggered. If using the log mailer, check storage/logs/laravel.log.');
        $this->info('If using SMTP, check your inbox/spam for the message: "TSMS Alert: Transaction Failure Threshold Exceeded"');

        return self::SUCCESS;
    }
}
