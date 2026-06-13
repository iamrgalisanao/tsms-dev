<div class="card mb-4">
  <div class="card-body">
    <!-- Basic Transaction Information -->
    <div class="row g-3 mb-4">
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

    <!-- Adjustments Section -->
    @if($log->adjustments && count($log->adjustments) > 0)
    <div class="row mb-4">
      <div class="col-12">
        <h6 class="text-muted mb-3">Adjustments</h6>
        <div class="row g-3">
          @foreach($log->adjustments as $adjustment)
          <div class="col-md-3 col-sm-6">
            <dl class="row mb-0">
              <dt class="col-12 small text-uppercase">{{ str_replace('_', ' ', $adjustment->adjustment_type) }}</dt>
              <dd class="col-12 fw-bold">{{ number_format($adjustment->amount, 2) }}</dd>
            </dl>
          </div>
          @endforeach
        </div>
      </div>
    </div>
    @endif

    <!-- Taxes Section -->
    @if($log->taxes && count($log->taxes) > 0)
    <div class="row">
      <div class="col-12">
        <h6 class="text-muted mb-3">Taxes</h6>
        <div class="row g-3">
          @foreach($log->taxes as $tax)
          <div class="col-md-3 col-sm-6">
            <dl class="row mb-0">
              <dt class="col-12 small text-uppercase">{{ str_replace('_', ' ', $tax->tax_type) }}</dt>
              <dd class="col-12 fw-bold">{{ number_format($tax->amount, 2) }}</dd>
            </dl>
          </div>
          @endforeach
        </div>
      </div>
    </div>
    @endif
  </div>
</div>