<?php

namespace App\Jobs\Reporting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backwards-compatible noop for previously-queued RefreshHourlyWindowJob instances.
 *
 * We keep a minimal class so workers can successfully unserialize existing queued
 * jobs. The handler is an immediate no-op and logs a clear message so you can
 * verify jobs are being skipped during the rollout.
 */
class RefreshHourlyWindowJob implements ShouldQueue
{
	use InteractsWithQueue, Queueable, SerializesModels;

	public $from;
	public $to;
	public $tenantId;
	public $terminalId;

	public function __construct(?string $from = null, ?string $to = null, $tenantId = null, $terminalId = null)
	{
		$this->from = $from;
		$this->to = $to;
		$this->tenantId = $tenantId;
		$this->terminalId = $terminalId;
		$this->onQueue('reporting');
	}

	public function handle()
	{
		// Job deprecated — immediately skip execution. Keep this lightweight
		// so workers can deserialize safely and move on.
		Log::info('RefreshHourlyWindowJob noop: job is deprecated and will not execute.', [
			'from' => $this->from,
			'to' => $this->to,
			'tenant' => $this->tenantId,
			'terminal' => $this->terminalId,
		]);
		return;
	}
}

