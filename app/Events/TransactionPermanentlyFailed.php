<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\IntegrationLog;

class TransactionPermanentlyFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The integration log that failed permanently.
     *
     * @var \App\Models\IntegrationLog
     */
    public $log;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\IntegrationLog  $log
     * @return void
     */
    public function __construct(IntegrationLog $log)
    {
        $this->log = $log;
    }
}
