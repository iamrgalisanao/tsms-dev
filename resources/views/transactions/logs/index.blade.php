@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5>Transaction Logs</h5>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" id="refreshBtn">
          <i class="fas fa-sync me-1"></i>Refresh
        </button>
        <a href="{{ route('transactions.logs.export') }}" class="btn btn-outline-success">
          <i class="fas fa-download me-1"></i>Export
        </a>
      </div>
    </div>

    <div class="card-body border-bottom">
      <!-- Simple Search -->
      <div class="row align-items-center">
        <div class="col-md-6">
          <div class="input-group">
            <input type="text" class="form-control" id="searchTransaction" placeholder="Search by Transaction ID...">
            <button class="btn btn-primary" onclick="applyFilters()">
              <i class="fas fa-search me-1"></i>Search
            </button>
          </div>
        </div>
        <div class="col-md-6 text-end">
          <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
            data-bs-target="#advancedFilters">
            <i class="fas fa-filter me-1"></i>Advanced Filters
          </button>
        </div>
      </div>

      <!-- Advanced Filters (Collapsed by default) -->
      <div class="collapse mt-3" id="advancedFilters">
        <div class="card card-body bg-light">
          <div class="row g-3">
            <div class="col-md-3">
              <select class="form-select" id="providerFilter">
                <option value="">All Providers</option>
                @foreach($providers as $provider)
                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select" id="terminalFilter">
                <option value="">All Terminals</option>
                @foreach($terminals as $terminal)
                <option value="{{ $terminal->id }}">{{ $terminal->identifier }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <input type="date" class="form-control" id="dateFilter">
            </div>
            <div class="col-md-3 text-end">
              <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
              <button class="btn btn-outline-secondary ms-2" onclick="resetFilters()">Reset</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
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

      <div class="d-flex justify-content-between align-items-center mt-4">
        <div class="text-muted">
          Showing {{ $logs->firstItem() ?? 0 }} to {{ $logs->lastItem() ?? 0 }} of {{ $logs->total() ?? 0 }} entries
        </div>
        <div>
          {{ $logs->links('vendor.pagination.bootstrap-5') }}
        </div>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// Add search on enter key
document.getElementById('searchTransaction')?.addEventListener('keyup', function(e) {
  if (e.key === 'Enter') {
    applyFilters();
  }
});

function resetFilters() {
  const searchInput = document.getElementById('searchTransaction');
  if (searchInput) {
    searchInput.value = '';
    // Clear search highlight if any
    document.querySelectorAll('.search-highlight').forEach(el => {
      el.classList.remove('search-highlight');
    });
  }
  document.getElementById('providerFilter').value = '';
  document.getElementById('terminalFilter').value = '';
  document.getElementById('dateFilter').value = '';
  document.querySelector('#advancedFilters').classList.remove('show');
  applyFilters();
}

function applyFilters() {
  const searchQuery = document.getElementById('searchTransaction')?.value.trim();

  // Build filters object
  const filters = {
    transaction_id: searchQuery, // Changed from 'search' to 'transaction_id'
    provider_id: document.getElementById('providerFilter')?.value || '',
    terminal_id: document.getElementById('terminalFilter')?.value || '',
    date: document.getElementById('dateFilter')?.value || ''
  };

  // Remove empty filters
  Object.keys(filters).forEach(key => {
    if (!filters[key]) delete filters[key];
  });

  // Log search attempt
  if (searchQuery) {
    console.log('Searching for transaction:', searchQuery);
  }

  const params = new URLSearchParams(filters);
  window.location.href = `${window.location.pathname}?${params}`;
}

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
@endsection