<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckTransactionFailureThresholdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?string $posTerminalId;

    /**
     * Create a new job instance.
     */
    public function __construct(?string $posTerminalId = null)
    {
        $this->posTerminalId = $posTerminalId;
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            Log::info('Running transaction failure threshold check', [
                'pos_terminal_id' => $this->posTerminalId,
            ]);

            $notificationService->checkTransactionFailureThresholds($this->posTerminalId);

            Log::info('Transaction failure threshold check completed', [
                'pos_terminal_id' => $this->posTerminalId,
            ]);
        } catch (\Exception $e) {
            Log::error('Transaction failure threshold check failed', [
                'pos_terminal_id' => $this->posTerminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckTransactionFailureThresholdsJob failed', [
            'pos_terminal_id' => $this->posTerminalId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
