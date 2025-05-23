@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
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

      <!-- Advanced Filters Card -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Advanced Filters</h5>
            <button type="button" class="btn btn-link p-0" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
              <i class="fas fa-filter"></i> Toggle Filters
            </button>
          </div>
        </div>
        <div class="collapse show" id="filterCollapse">
          <div class="card-body">
            <form id="filterForm" class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Date Range</label>
                <div class="input-group">
                  <input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}">
                  <span class="input-group-text">to</span>
                  <input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}">
                </div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Amount Range</label>
                <div class="input-group">
                  <input type="number" class="form-control" name="amount_min" placeholder="Min"
                    value="{{ request('amount_min') }}">
                  <span class="input-group-text">to</span>
                  <input type="number" class="form-control" name="amount_max" placeholder="Max"
                    value="{{ request('amount_max') }}">
                </div>
              </div>

              <div class="col-md-2">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider_id">
                  <option value="">All Providers</option>
                  @foreach($providers as $provider)
                  <option value="{{ $provider->id }}" {{ request('provider_id') == $provider->id ? 'selected' : '' }}>
                    {{ $provider->name }}
                  </option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-2">
                <label class="form-label">Terminal</label>
                <select class="form-select" name="terminal_id">
                  <option value="">All Terminals</option>
                  @foreach($terminals as $terminal)
                  <option value="{{ $terminal->id }}" {{ request('terminal_id') == $terminal->id ? 'selected' : '' }}>
                    {{ $terminal->identifier }}
                  </option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">
          <div class="row g-3">
            <div class="col-md-2">
              <select class="form-select form-select-sm" id="providerFilter" name="provider_id">
                <option value="">All Providers</option>
                @foreach($providers as $provider)
                <option value="{{ $provider->id }}" {{ request('provider_id') == $provider->id ? 'selected' : '' }}>
                  {{ $provider->name }}
                </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" id="terminalFilter" name="terminal_id">
                <option value="">All Terminals</option>
                @foreach($terminals as $terminal)
                <option value="{{ $terminal->id }}" {{ request('terminal_id') == $terminal->id ? 'selected' : '' }}>
                  {{ $terminal->identifier }}
                </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control form-control-sm" id="dateFrom" name="date_from"
                value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control form-control-sm" id="dateTo" name="date_to"
                value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2">
              <input type="number" class="form-control form-control-sm" id="amountMin" name="amount_min"
                placeholder="Min Amount" value="{{ request('amount_min') }}">
            </div>
            <div class="col-md-2">
              <input type="number" class="form-control form-control-sm" id="amountMax" name="amount_max"
                placeholder="Max Amount" value="{{ request('amount_max') }}">
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="row mb-3">
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

document.querySelectorAll('.filter-control').forEach(control => {
  control.addEventListener('change', function() {
    applyFilters();
  });
});

function applyFilters() {
  const filters = {
    provider_id: document.getElementById('providerFilter').value,
    terminal_id: document.getElementById('terminalFilter').value,
    date_from: document.getElementById('dateFrom').value,
    date_to: document.getElementById('dateTo').value,
    amount_min: document.getElementById('amountMin').value,
    amount_max: document.getElementById('amountMax').value
  };

  const queryString = new URLSearchParams(filters).toString();
  window.location.href = `${window.location.pathname}?${queryString}`;
}

// Add filter form handling
document.addEventListener('DOMContentLoaded', function() {
  const filterForm = document.getElementById('filterForm');
  filterForm?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const params = new URLSearchParams(formData);
    window.location.href = `${window.location.pathname}?${params.toString()}`;
  });
});
</script>
@endpush