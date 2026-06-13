<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DeadlockRetryService
{
    /**
     * Retry a database transaction on deadlocks, serialization failures, and lock wait timeouts.
     */
    public function withDeadlockRetry(callable $callback, int $maxAttempts = 5)
    {
        $attempt = 0;

        retry:
        try {
            return DB::transaction($callback, 1);
        } catch (QueryException $e) {
            $attempt++;
            $message = $e->getMessage();
            $retryable =
                str_contains($message, 'Deadlock found when trying to get lock') ||
                str_contains($message, 'SQLSTATE[40001]') ||
                str_contains($message, 'Lock wait timeout exceeded');

            if ($retryable && $attempt < $maxAttempts) {
                usleep(random_int(50000, 150000) * $attempt);
                goto retry;
            }

            throw $e;
        }
    }
}
