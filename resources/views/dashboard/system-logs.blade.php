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
      <a class="nav-link" href="#system" data-bs-toggle="tab">
        <i class="fas fa-cogs me-2"></i>System Logs
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link active" href="#audit" data-bs-toggle="tab">
        <i class="fas fa-history me-2"></i>Audit Trail
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="#webhook" data-bs-toggle="tab">
        <i class="fas fa-exchange-alt me-2"></i>Webhook Logs
      </a>
    </li>
  </ul>

  <div class="tab-content">
    <!-- System Logs Tab -->
    <div class="tab-pane fade" id="system">
      @include('logs.partials.system-table')
    </div>
    
    <!-- Audit Trail Tab -->
    <div class="tab-pane fade show active" id="audit">
      @include('logs.partials.audit-table')
    </div>
    
    <!-- Webhook Logs Tab -->
    <div class="tab-pane fade" id="webhook">
      @include('logs.partials.webhook-table')
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