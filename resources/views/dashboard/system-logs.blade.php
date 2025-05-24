@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="card">
    <div class="card-header">
      <h5>System Logs</h5>
    </div>
    <div class="card-body">
      <!-- Log Statistics -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="card bg-light">
            <div class="card-body">
              <h6>Transaction Logs</h6>
              <h3 class="mb-0">{{ number_format($stats['transactions']) }}</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-danger text-white">
            <div class="card-body">
              <h6>Errors</h6>
              <h3 class="mb-0">{{ number_format($stats['errors']) }}</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-warning">
            <div class="card-body">
              <h6>Warnings</h6>
              <h3 class="mb-0">{{ $stats['warnings'] ?? 0 }}</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-info text-white">
            <div class="card-body">
              <h6>Info</h6>
              <h3 class="mb-0">{{ $stats['info'] ?? 0 }}</h3>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="row mb-3">
        <div class="col-md-2">
          <select class="form-select" id="logType">
            <option value="">All Types</option>
            <option value="transaction">Transaction</option>
            <option value="system">System</option>
            <option value="auth">Authentication</option>
            <option value="error">Error</option>
            <option value="job">Job Processing</option>
          </select>
        </div>
        <div class="col-md-2">
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
        <div class="col-md-3">
          <input type="text" class="form-control" id="searchLogs" placeholder="Search logs...">
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary w-100" onclick="applyFilters()">Apply Filters</button>
        </div>
      </div>

      <!-- Logs Table -->
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Time</th>
              <th>Type</th>
              <th>Severity</th>
              <th>Terminal</th>
              <th>Message</th>
              <th>Transaction</th>
              <th>Details</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="logsTableBody">
            @forelse($logs as $log)
            <tr>
              <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
              <td><span class="badge bg-{{ $log->type_class }}">{{ ucfirst($log->type) }}</span></td>
              <td><span class="badge bg-{{ $log->severity_class }}">{{ strtoupper($log->severity) }}</span></td>
              <td>{{ $log->terminal_uid ?? 'N/A' }}</td>
              <td class="text-wrap" style="max-width: 300px;">{{ $log->message }}</td>
              <td>
                @if($log->transaction_id)
                <a href="{{ route('transactions.show', $log->transaction_id) }}" class="text-primary">
                  {{ $log->transaction_id }}
                </a>
                @else
                N/A
                @endif
              </td>
              <td>
                @if($log->context)
                <span class="badge bg-secondary" role="button" onclick="showContext('{{ $log->id }}')">
                  View Context
                </span>
                @endif
              </td>
              <td>
                <button class="btn btn-sm btn-info" onclick="viewDetails('{{ $log->id }}')">
                  <i class="fas fa-eye"></i>
                </button>
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="8" class="text-center">No logs found</td>
            </tr>
            @endforelse
          </tbody>
        </table>
        {{ $logs->links() }}
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
function applyFilters() {
  const type = document.getElementById('logType').value;
  const severity = document.getElementById('severity').value;
  const date = document.getElementById('dateFilter').value;
  const search = document.getElementById('searchLogs').value;

  fetch(`/api/logs?type=${type}&severity=${severity}&date=${date}&search=${search}`)
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