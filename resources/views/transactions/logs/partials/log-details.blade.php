<div class="card mb-4">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <dl class="row mb-0">
          <dt class="col-sm-4">Transaction ID</dt>
          <dd class="col-sm-8">{{ $log->transaction_id }}</dd>

          <dt class="col-sm-4">Terminal</dt>
          <dd class="col-sm-8">{{ $log->terminal->identifier ?? 'N/A' }}</dd>

          <dt class="col-sm-4">Amount</dt>
          <dd class="col-sm-8">{{ number_format($log->gross_sales, 2) }}</dd>
        </dl>
      </div>
      <div class="col-md-6">
        <dl class="row mb-0">
          <dt class="col-sm-4">Status</dt>
          <dd class="col-sm-8">
            <span class="badge bg-{{ $log->validation_status_color }}">
              {{ $log->validation_status }}
            </span>
          </dd>

          <dt class="col-sm-4">Attempts</dt>
          <dd class="col-sm-8">{{ $log->job_attempts }}</dd>

          <dt class="col-sm-4">Created</dt>
          <dd class="col-sm-8">{{ $log->created_at->format('Y-m-d H:i:s') }}</dd>
        </dl>
      </div>
    </div>
  </div>
</div>