@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="row mb-4">
    <div class="col">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Transaction Logs</h2>
        <div>
          <button class="btn btn-primary me-2" id="refreshBtn">
            <i class="fas fa-sync"></i> Refresh
          </button>
          <a href="{{ route('transactions.logs.export') }}" class="btn btn-success">
            <i class="fas fa-download"></i> Export
          </a>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <div class="row mb-3">
            <!-- Add filters -->
            <div class="col-md-3">
              <select class="form-select" id="validationFilter">
                <option value="">All Validation Status</option>
                <option value="VALID">Valid</option>
                <option value="ERROR">Error</option>
                <option value="PENDING">Pending</option>
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select" id="jobStatusFilter">
                <option value="">All Job Status</option>
                <option value="COMPLETED">Completed</option>
                <option value="FAILED">Failed</option>
                <option value="QUEUED">Queued</option>
              </select>
            </div>
            <div class="col-md-3">
              <input type="date" class="form-control" id="dateFilter">
            </div>
            <div class="col-md-3">
              <button type="button" class="btn btn-secondary w-100" id="applyFilters">
                Apply Filters
              </button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>Transaction ID</th>
                  <th>Terminal</th>
                  <th>Amount</th>
                  <th>Validation Status</th>
                  <th>Job Status</th>
                  <th>Attempts</th>
                  <th>Created At</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="logsTableBody">
                @foreach($logs as $log)
                @include('transactions.logs.partials.log-row', ['log' => $log])
                @endforeach
              </tbody>
            </table>
          </div>

          <div class="mt-4">
            {{ $logs->links() }}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
// Real-time updates
window.Echo?.private('transactions')
  .listen('TransactionStatusUpdated', (e) => {
    const transaction = e.transaction;
    updateTransactionRow(transaction);
  });

function updateTransactionRow(transaction) {
  const row = document.querySelector(`tr[data-id="${transaction.id}"]`);
  if (row) {
    row.querySelector('.validation-status').innerHTML = getStatusBadge(transaction.validation_status);
    row.querySelector('.job-status').innerHTML = getStatusBadge(transaction.job_status, 'job');
    row.querySelector('.attempts').textContent = transaction.job_attempts;
    row.querySelector('.completed-at').textContent = formatDate(transaction.completed_at);
  }
}

function getStatusBadge(status, type = 'validation') {
  const colors = {
    'VALID': 'success',
    'ERROR': 'danger',
    'PENDING': 'warning',
    'COMPLETED': 'success',
    'FAILED': 'danger',
    'QUEUED': 'info'
  };
  return `<span class="badge bg-${colors[status] || 'secondary'}">${status}</span>`;
}

function formatDate(date) {
  return date ? new Date(date).toLocaleString() : 'N/A';
}

// Export functionality
document.getElementById('exportBtn')?.addEventListener('click', function() {
  window.location.href = "{{ route('transactions.logs.export') }}?" + new URLSearchParams(filters);
});

// Filter functionality
document.getElementById('applyFilters')?.addEventListener('click', function() {
  filters.validation_status = document.getElementById('validationFilter').value;
  filters.job_status = document.getElementById('jobStatusFilter').value;
  filters.date = document.getElementById('dateFilter').value;

  refreshLogs();
});
</script>
@endpush