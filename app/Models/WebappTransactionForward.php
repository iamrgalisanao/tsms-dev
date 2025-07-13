<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class WebappTransactionForward extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'webapp_transaction_forwards';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'transaction_id',
        'batch_id',
        'status',
        'attempts',
        'max_attempts',
        'first_attempted_at',
        'last_attempted_at',
        'completed_at',
        'request_payload',
        'response_data',
        'response_status_code',
        'error_message',
        'next_retry_at',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'request_payload' => 'array',
        'response_data' => 'array',
        'metadata' => 'array',
        'first_attempted_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Get the transaction that this forward belongs to
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Scope to get pending forwards
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed forwards
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed forwards
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to get forwards ready for retry
     */
    public function scopeReadyForRetry(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED)
                    ->where('attempts', '<', 'max_attempts')
                    ->where(function ($q) {
                        $q->whereNull('next_retry_at')
                          ->orWhere('next_retry_at', '<=', now());
                    });
    }

    /**
     * Scope to get forwards by batch
     */
    public function scopeByBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    /**
     * Mark the forward as in progress
     */
    public function markAsInProgress(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'last_attempted_at' => now(),
            'first_attempted_at' => $this->first_attempted_at ?? now(),
        ]);
    }

    /**
     * Mark the forward as completed
     */
    public function markAsCompleted(array $responseData = [], int $statusCode = 200): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'response_data' => $responseData,
            'response_status_code' => $statusCode,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the forward as failed with retry scheduling
     */
    public function markAsFailed(string $errorMessage, int $statusCode = null): void
    {
        $this->increment('attempts');
        
        $nextRetryAt = null;
        if ($this->attempts < $this->max_attempts) {
            // Exponential backoff: 5 minutes * 2^(attempts-1)
            $delayMinutes = 5 * pow(2, $this->attempts - 1);
            // Cap at 2 hours maximum
            $delayMinutes = min($delayMinutes, 120);
            $nextRetryAt = now()->addMinutes($delayMinutes);
        }

        $this->update([
            'status' => $this->attempts >= $this->max_attempts ? self::STATUS_FAILED : self::STATUS_PENDING,
            'last_attempted_at' => now(),
            'error_message' => $errorMessage,
            'response_status_code' => $statusCode,
            'next_retry_at' => $nextRetryAt,
        ]);
    }

    /**
     * Check if this forward can be retried
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->attempts < $this->max_attempts
            && ($this->next_retry_at === null || $this->next_retry_at <= now());
    }

    /**
     * Get retry delay for next attempt
     */
    public function getRetryDelayMinutes(): int
    {
        if ($this->attempts >= $this->max_attempts) {
            return 0;
        }

        $delayMinutes = 5 * pow(2, $this->attempts);
        return min($delayMinutes, 120); // Cap at 2 hours
    }

    /**
     * Check if forward is in final state (completed or permanently failed)
     */
    public function isFinalState(): bool
    {
        return $this->status === self::STATUS_COMPLETED 
            || ($this->status === self::STATUS_FAILED && $this->attempts >= $this->max_attempts);
    }
}
