<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class DeadlockRetryService
{
    /**
     * Retry a database transaction on deadlock/serialization failure/lock wait timeout.
     *
     * @param callable $callback
     * @param int $maxAttempts
     * @return mixed
     * @throws QueryException
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
