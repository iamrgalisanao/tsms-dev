@extends('layouts.master')

@section('content')
<div class="container-fluid py-4">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="text-gray-900 mb-1">Transaction Details</h4>
      <p class="text-muted mb-0">{{ $transaction->transaction_id }}</p>
    </div>
    <div class="d-flex gap-2">
      @if($transaction->validation_status === 'ERROR' && Gate::check('retry-transactions'))
      <button class="btn btn-warning" id="retryBtn">
        <i class="fas fa-sync-alt me-2"></i> Retry Transaction
      </button>
      @elseif($transaction->validation_status === 'ERROR')
      <span class="text-muted small">You don’t have permission to retry transactions.</span>
      @endif
      <a href="{{ route('transactions.logs.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i> Back to Logs
      </a>
    </div>
  </div>

  <!-- Transaction Details Grid -->
  <div class="row g-4">
    <!-- Status Card -->
    <div class="col-md-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex flex-column h-100">
            <h6 class="text-muted mb-2">Status</h6>
            <div class="mt-auto">
              <span
                class="badge bg-{{ $transaction->validation_status === 'VALID' ? 'success' : ($transaction->validation_status === 'ERROR' ? 'danger' : 'warning') }}-subtle text-{{ $transaction->validation_status === 'VALID' ? 'success' : ($transaction->validation_status === 'ERROR' ? 'danger' : 'warning') }} px-3 py-2">
                <i
                  class="fas fa-{{ $transaction->validation_status === 'VALID' ? 'check-circle' : ($transaction->validation_status === 'ERROR' ? 'exclamation-circle' : 'clock') }} me-2"></i>
                {{ $transaction->validation_status }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add more detail cards here -->
    <div class="col-md-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="text-muted mb-2">Processing Time</h6>
          <div class="d-flex align-items-center mt-3">
            <i class="fas fa-clock text-primary me-2"></i>
            <h3 class="mb-0">{{ $metrics['processing_time'] ?? 0 }}s</h3>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="text-muted mb-2">Amount</h6>
          <div class="d-flex align-items-center mt-3">
            <i class="fas fa-money-bill text-success me-2"></i>
            <h3 class="mb-0">₱{{ number_format($transaction->gross_sales, 2) }}</h3>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="text-muted mb-2">Terminal</h6>
          <div class="mt-3">
            <p class="mb-1 text-dark">{{ optional($transaction->terminal)->identifier ?? 'N/A' }}</p>
            @php
              $sn = optional($transaction->terminal)->serial_number;
              $mn = optional($transaction->terminal)->machine_number;
            @endphp
            <small class="text-muted">SN: {{ $sn ?? 'N/A' }} • Machine: {{ $mn ?? 'N/A' }}</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Timeline Section -->
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent py-3">
          <h5 class="mb-0">Processing Timeline</h5>
        </div>
        <div class="card-body px-4">
          <div class="timeline position-relative">
            @foreach($timeline as $event)
            <div class="timeline-item">
              <div class="timeline-marker bg-{{ $event['status_color'] }}"></div>
              <div class="timeline-content bg-white rounded-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span
                    class="badge bg-{{ $event['status_color'] }}-subtle text-{{ $event['status_color'] }} px-3 py-2">
                    {{ $event['status'] }}
                  </span>
                  <small class="text-muted">
                    <i class="far fa-clock me-1"></i>
                    {{ \Carbon\Carbon::parse($event['timestamp'])->format('M d, Y h:i:s A') }}
                  </small>
                </div>
                <p class="mb-0 text-gray-600">{{ $event['message'] }}</p>
                @if(!empty($event['metadata']))
                <div class="mt-2">
                  <pre
                    class="bg-light p-2 rounded small mb-0">{{ json_encode($event['metadata'], JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
              </div>
            </div>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
document.getElementById('retryBtn')?.addEventListener('click', function() {
  if (confirm('Are you sure you want to retry this transaction?')) {
    fetch(`{{ route('transactions.retry', $transaction->id) }}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
          'Accept': 'application/json'
        }
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          location.reload();
        }
      });
  }
});
</script>
@endpush
@endsection