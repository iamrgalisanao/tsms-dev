@extends('layouts.app')

@section('content')
@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

<div class="container-fluid py-4">
  <!-- Add Tab Navigation -->
  <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
      <a class="nav-link active" href="{{ route('system-logs.index') }}">System Logs</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="{{ route('system-logs.audit-trail') }}">Audit Trail</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="{{ route('system-logs.webhook-logs') }}">Webhook Logs</a>
    </li>
  </ul>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5>System Logs</h5>
      <div class="d-flex gap-2">
        <!-- Export Options -->
        <div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="fas fa-download me-1"></i>Export
          </button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('system-logs.export', ['format' => 'csv']) }}">CSV</a></li>
            <li><a class="dropdown-item" href="{{ route('system-logs.export', ['format' => 'pdf']) }}">PDF</a></li>
          </ul>
        </div>
        <!-- Live Updates Toggle -->
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="liveUpdate" checked>
          <label class="form-check-label" for="liveUpdate">Live Updates</label>
        </div>
      </div>
    </div>

    <div class="card-body border-bottom">
      <!-- Simple Search -->
      <div class="row align-items-center">
        <div class="col-md-6">
          <div class="input-group">
            <input type="text" class="form-control" id="searchLogs" placeholder="Search by Transaction ID...">
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
              <select class="form-select" id="logType">
                <option value="">All Types</option>
                <option value="transaction">Transaction</option>
                <option value="system">System</option>
                <option value="auth">Authentication</option>
                <option value="error">Error</option>
                <option value="job">Job Processing</option>
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select" id="severity">
                <option value="">All Severities</option>
                <option value="error">Error</option>
                <option value="warning">Warning</option>
                <option value="info">Info</option>
                <option value="debug">Debug</option>
              </select>
            </div>
            <div class="col-md-3">
              <input type="date" class="form-control" id="dateFilter" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-3 text-end">
              <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
              <button class="btn btn-outline-secondary ms-2" onclick="resetFilters()">Reset</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
      <div class="col-md-3">
        <div class="card bg-primary text-white">
          <div class="card-body">
            <h6>System Events</h6>
            <h3 class="mb-0">{{ number_format($stats['system'] ?? 0) }}</h3>
            <small>Last 24 Hours</small>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card bg-danger text-white">
          <div class="card-body">
            <h6>Failed</h6>
            <h3 class="mb-0">{{ number_format($stats['errors']) }}</h3>
            <small>Last 24 Hours</small>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card bg-warning">
          <div class="card-body">
            <h6>Retries</h6>
            <h3 class="mb-0">{{ $stats['retries'] ?? 0 }}</h3>
            <small>Pending Retries</small>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card bg-success text-white">
          <div class="card-body">
            <h6>Completed</h6>
            <h3 class="mb-0">{{ $stats['completed'] ?? 0 }}</h3>
            <small>Last Hour</small>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card bg-info text-white">
          <div class="card-body">
            <h6>Webhook Status</h6>
            <h3 class="mb-0">{{ number_format($stats['webhook_errors'] ?? 0) }}</h3>
            <small>Failed Webhooks (24h)</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Enhanced Logs Table -->
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Time</th>
            <th>Type</th>
            <th>Status</th>
            <th>Terminal</th>
            <th>Error Details</th>
            <th>Transaction</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $log)
          <tr>
            <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
            <td>
              <span class="badge bg-{{ LogHelper::getLogTypeClass($log->log_type) }}">
                {{ ucfirst($log->log_type) }}
              </span>
            </td>
            <td>
              <div class="d-flex gap-2">
                <span class="badge bg-{{ BadgeHelper::getStatusBadgeColor($log->severity) }}">
                  {{ strtoupper($log->severity) }}
                </span>
              </div>
            </td>
            <td class="text-nowrap">{{ $log->terminal_uid ?? 'N/A' }}</td>
            <td class="text-wrap" style="max-width: 300px;">
              <small class="text-muted">{{ $log->message }}</small>
            </td>
            <td class="text-nowrap">
              @if($log->transaction_id)
              <a href="{{ route('transactions.show', $log->transaction_id) }}"
                class="btn btn-sm btn-link text-decoration-none">
                {{ $log->transaction_id }}
              </a>
              @else
              <span class="text-muted">N/A</span>
              @endif
            </td>
            <td class="text-center">
              @if($log->context)
              <button class="btn btn-sm btn-outline-primary" onclick="showContext('{{ $log->id }}')">
                <i class="fas fa-search me-1"></i>Details
              </button>
              @endif
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="7" class="text-center py-4">
              <div class="text-muted">
                <i class="fas fa-info-circle me-1"></i>No system logs found
              </div>
            </td>
          </tr>
          @endforelse
        </tbody>
      </table>

      <!-- Enhanced Pagination -->
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

<!-- Context Modal -->
<div class="modal fade" id="contextModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log Context Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre><code id="contextContent"></code></pre>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// Add helper function for badge classes
function getBadgeClass(status) {
  const classes = {
    'VALID': 'success',
    'INVALID': 'danger',
    'PENDING': 'warning',
    'PROCESSING': 'info',
    'COMPLETED': 'success',
    'FAILED': 'danger',
    'QUEUED': 'secondary'
  };
  return classes[status.toUpperCase()] || 'secondary';
}

function getBadgeColor(status) {
  return {
    'VALID': 'success',
    'INVALID': 'danger',
    'PENDING': 'warning',
    'PROCESSING': 'info',
    'COMPLETED': 'success',
    'FAILED': 'danger',
    'QUEUED': 'secondary'
  } [status.toUpperCase()] || 'secondary';
}

function getSeverityColor(severity) {
  return {
    'ERROR': 'danger',
    'WARNING': 'warning',
    'INFO': 'info',
    'DEBUG': 'secondary'
  } [severity.toUpperCase()] || 'secondary';
}

function resetFilters() {
  document.getElementById('searchLogs').value = '';
  document.getElementById('logType').value = '';
  document.getElementById('severity').value = '';
  document.getElementById('dateFilter').value = '';
  document.querySelector('#advancedFilters').classList.remove('show');
  applyFilters();
}

// Update apply filters to handle both simple and advanced search
function applyFilters() {
  const searchQuery = document.getElementById('searchLogs').value;
  const type = document.getElementById('logType')?.value || '';
  const severity = document.getElementById('severity')?.value || '';
  const date = document.getElementById('dateFilter')?.value || '';

  fetch(`/api/logs?search=${searchQuery}&type=${type}&severity=${severity}&date=${date}`)
    .then(response => response.json())
    .then(data => updateLogsTable(data));
}

function viewDetails(logId) {
  window.location.href = `/dashboard/logs/${logId}`;
}

function showContext(logId) {
  const modal = new bootstrap.Modal(document.getElementById('contextModal'));
  fetch(`/api/logs/${logId}/context`)
    .then(response => response.json())
    .then(data => {
      document.getElementById('contextContent').textContent = JSON.stringify(data, null, 2);
      modal.show();
    });
}

// Enable live updates with active checkbox
const liveUpdates = document.createElement('div');
liveUpdates.className = 'form-check form-switch ms-3';
liveUpdates.innerHTML = `
  <input class="form-check-input" type="checkbox" id="liveUpdate" checked>
  <label class="form-check-label" for="liveUpdate">Live Updates</label>
`;
document.querySelector('.card-header').appendChild(liveUpdates);

let updateInterval;
document.getElementById('liveUpdate').addEventListener('change', function() {
  if (this.checked) {
    updateInterval = setInterval(applyFilters, 30000);
  } else {
    clearInterval(updateInterval);
  }
});

// Initialize live updates
updateInterval = setInterval(applyFilters, 30000);
</script>
@endpush
@endsection