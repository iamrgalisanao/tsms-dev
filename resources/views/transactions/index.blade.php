@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="row mb-4">
    <div class="col">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Transactions</h2>
        <button class="btn btn-primary" id="refreshBtn">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>

      <div class="card">
        <div class="card-header bg-white">
          <div class="row g-2">
            <div class="col-md-3">
              <select class="form-select form-select-sm" id="validationStatus">
                <option value="">All Validation Status</option>
                <option value="VALID">Valid</option>
                <option value="ERROR">Error</option>
                <option value="PENDING">Pending</option>
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select form-select-sm" id="jobStatus">
                <option value="">All Job Status</option>
                <option value="COMPLETED">Completed</option>
                <option value="FAILED">Failed</option>
                <option value="QUEUED">Queued</option>
              </select>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Transaction ID</th>
                  <th>Terminal</th>
                  <th class="text-end">Amount</th>
                  <th class="text-center">Validation</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Attempts</th>
                 <th class="text-center">Job Debug</th>
                  <th>Created At</th>
                  <th class="text-end pe-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                @forelse($transactions as $transaction)
                <tr data-id="{{ $transaction->id }}">
                  <td class="ps-3">{{ $transaction->transaction_id }}</td>
                  <td>{{ $transaction->terminal->identifier ?? 'N/A' }}</td>
                  <td class="text-end">₱{{ number_format($transaction->gross_sales, 2) }}</td>
                  <td class="text-center validation-status">
                    <span
                      class="badge bg-{{ $transaction->validation_status === 'VALID' ? 'success' : ($transaction->validation_status === 'ERROR' ? 'danger' : 'warning') }}">
                      {{ $transaction->validation_status }}
                    </span>
                  </td>
                  <td class="text-center job-status">
                    <span
                      class="badge bg-{{ $transaction->latest_job_status === 'COMPLETED' ? 'success' : ($transaction->latest_job_status === 'FAILED' ? 'danger' : 'info') }}">
                      {{ $transaction->latest_job_status ?? 'QUEUED' }}
                    </span>
                  </td>
                  <td class="text-center attempts">{{ $transaction->job_attempts }}</td>
                 <td class="text-center">
                   @foreach($transaction->jobs as $job)
                     <div style="font-size: 11px; margin-bottom: 2px;">
                       <strong>Status:</strong> {{ $job->job_status }}<br>
                       <strong>Attempt:</strong> {{ $job->attempts ?? $job->attempt_number ?? 'N/A' }}<br>
                       <strong>Completed:</strong> {{ $job->completed_at ?? 'N/A' }}
                     </div>
                   @endforeach
                   @if($transaction->jobs->isEmpty())
                     <span class="text-muted">No jobs</span>
                   @endif
                 </td>
                  <td>{{ $transaction->created_at->format('M d, Y h:i A') }}</td>
                  <td class="text-end pe-3">
                    <div class="d-flex gap-2 justify-content-end">
                      <a href="{{ route('transactions.show', $transaction->id) }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Details
                      </a>
                      @if($transaction->validation_status === 'ERROR')
                      <button type="button" class="btn btn-sm btn-warning retry-transaction"
                        data-id="{{ $transaction->id }}">
                        <i class="fas fa-sync-alt"></i> Retry
                      </button>
                      @endif
                    </div>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="8" class="text-center py-4">No transactions found</td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        @if($transactions->hasPages())
        <div class="card-footer bg-white">
          <div class="d-flex justify-content-end">
            {{ $transactions->links() }}
          </div>
        </div>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
.table th {
  white-space: nowrap;
}

.badge {
  font-weight: 500;
}

.pagination {
  margin-bottom: 0;
}
</style>
@endpush

@push('scripts')
<script>
// Add retry functionality
document.querySelectorAll('.retry-transaction').forEach(button => {
  button.addEventListener('click', function() {
    const transactionId = this.dataset.id;
    if (confirm('Are you sure you want to retry processing this transaction?')) {
      // Add retry logic here
      console.log('Retrying transaction:', transactionId);
    }
  });
});

Echo.private('transactions')
  .listen('TransactionStatusUpdated', (e) => {
    const transaction = e.transaction;
    const row = document.querySelector(`tr[data-id="${transaction.id}"]`);
    if (row) {
      // Update status badges and information
      updateTransactionRow(row, transaction);
    }
  });

function updateTransactionRow(row, transaction) {
  // Update status badges and information
  row.querySelector('.validation-status').innerHTML = getStatusBadge(transaction.validation_status);
  row.querySelector('.job-status').innerHTML = getStatusBadge(transaction.job_status, 'job');
  row.querySelector('.attempts').textContent = transaction.job_attempts;
}

function getStatusBadge(status, type = 'validation') {
  let badgeClass = '';
  if (type === 'validation') {
    badgeClass = status === 'VALID' ? 'success' : (status === 'ERROR' ? 'danger' : 'warning');
  } else if (type === 'job') {
    badgeClass = status === 'COMPLETED' ? 'success' : (status === 'FAILED' ? 'danger' : 'info');
  }
  return `<span class="badge bg-${badgeClass}">${status}</span>`;
}
</script>
@endpush