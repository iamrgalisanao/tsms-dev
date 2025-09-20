@extends('layouts.app')

@section('content')
@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

<div class="container-fluid py-4">
  <!-- Page Header -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center">
        <h4 class="mb-0 text-dark" id="dashboardTitle">System Logs Dashboard</h4>
        <div class="d-flex gap-3">
          <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Export logs">
              <i class="fas fa-download me-2" aria-hidden="true"></i>Export
            </button>
            <ul class="dropdown-menu" aria-labelledby="exportDropdown">
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'csv']) }}" aria-label="Export as CSV">CSV</a></li>
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'pdf']) }}" aria-label="Export as PDF">PDF</a></li>
            </ul>
          </div>
          <div class="form-check form-switch d-flex align-items-center">
            <input class="form-check-input me-2" type="checkbox" id="liveUpdate" checked aria-label="Toggle live updates">
            <label class="form-check-label text-muted" for="liveUpdate">Live Updates</label>
            <span id="liveUpdateSpinner" class="ms-2" style="display:none;" aria-live="polite"><i class="fas fa-sync fa-spin text-primary"></i></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <div class="flex-shrink-0 bg-primary bg-opacity-10 p-3 rounded">
              <i class="fas fa-cogs fa-lg text-primary"></i>
            </div>
            <div class="flex-grow-1 ms-3">
              <h3 class="mb-0">{{ number_format($stats['system'] ?? 0) }}</h3>
              <small class="text-muted">System Events</small>
            </div>
          </div>
          <div class="progress" style="height: 4px">
            <div class="progress-bar bg-primary" style="width: 100%"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <div class="flex-shrink-0 bg-success bg-opacity-10 p-3 rounded">
              <i class="fas fa-check-circle fa-lg text-success"></i>
            </div>
            <div class="flex-grow-1 ms-3">
              <h3 class="mb-0">{{ number_format($stats['success'] ?? 0) }}</h3>
              <small class="text-muted">Successful Events</small>
            </div>
          </div>
          <div class="progress" style="height: 4px">
            <div class="progress-bar bg-success" style="width: 75%"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <div class="flex-shrink-0 bg-danger bg-opacity-10 p-3 rounded">
              <i class="fas fa-exclamation-circle fa-lg text-danger"></i>
            </div>
            <div class="flex-grow-1 ms-3">
              <h3 class="mb-0">{{ number_format($stats['errors'] ?? 0) }}</h3>
              <small class="text-muted">Error Events</small>
            </div>
          </div>
          <div class="progress" style="height: 4px">
            <div class="progress-bar bg-danger" style="width: 25%"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <div class="flex-shrink-0 bg-warning bg-opacity-10 p-3 rounded">
              <i class="fas fa-clock fa-lg text-warning"></i>
            </div>
            <div class="flex-grow-1 ms-3">
              <h3 class="mb-0">{{ number_format($stats['pending'] ?? 0) }}</h3>
              <small class="text-muted">Pending Events</small>
            </div>
          </div>
          <div class="progress" style="height: 4px">
            <div class="progress-bar bg-warning" style="width: 50%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content Area -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
      <ul class="nav nav-tabs card-header-tabs">
        <li class="nav-item">
          <a class="nav-link active fw-medium text-muted" href="#audit" data-bs-toggle="tab">
            <i class="fas fa-history me-2"></i>Audit Trail
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link fw-medium text-muted" href="#webhook" data-bs-toggle="tab">
            <i class="fas fa-exchange-alt me-2"></i>Webhook Logs
          </a>
        </li>
      </ul>
    </div>

    <div class="card-body p-4">
      <!-- Search -->
      <div class="row g-3 mb-4">
        <div class="col-md-8 col-lg-6">
          <div class="input-group">
            <input type="text" class="form-control" id="searchLogs" placeholder="Search audit logs..." aria-label="Search logs">
            <button class="btn btn-outline-secondary" type="button" id="clearSearch" aria-label="Clear search" tabindex="0"><i class="fas fa-times"></i></button>
            <button class="btn btn-primary px-4" onclick="applyFilters()" aria-label="Search logs">
              <i class="fas fa-search me-2" aria-hidden="true"></i>Search
            </button>
          </div>
        </div>
      </div>

      <!-- Tab Content -->
      <div class="tab-content">
        <div class="tab-pane fade show active" id="audit">
          @include('logs.partials.audit-table')
        </div>
        <div class="tab-pane fade" id="webhook">
          @include('logs.partials.webhook-table')
        </div>
        <!-- Empty State -->
        <div id="emptyState" class="text-center py-5" style="display:none;">
          <i class="fas fa-folder-open fa-3x text-muted mb-3" aria-hidden="true"></i>
          <h5 class="text-muted">No logs found</h5>
        </div>
      </div>
    </div>
  </div>
</div>

@include('logs.partials.context-modal')

@push('styles')
@push('scripts')
<script>
$(document).ready(function() {
  // Clear search input
  $('#clearSearch').on('click', function() {
    $('#searchLogs').val('');
    applyFilters();
  });

  // Main search button
  $('#searchLogs').on('keypress', function(e) {
    if (e.which === 13) {
      applyFilters();
    }
  });

  // Live update toggle
  $('#liveUpdate').on('change', function() {
    if ($(this).is(':checked')) {
      startLiveUpdates();
    } else {
      stopLiveUpdates();
    }
  });

  // Initial load
  applyFilters();
});
  // Log details modal handler
  $(document).on('click', '.view-details-btn', function() {
    const logId = $(this).data('log-id');
    $('#contextModal .modal-body').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>');
    $('#contextModal').modal('show');
    $.ajax({
      url: '/logs/details/' + logId,
      method: 'GET',
      success: function(response) {
        $('#contextModal .modal-body').html(response.html);
      },
      error: function() {
        $('#contextModal .modal-body').html('<div class="text-danger">Failed to load log details.</div>');
      }
    });
  });

let liveUpdateInterval = null;

function applyFilters() {
  $('#liveUpdateSpinner').show();
  // Gather filter values
  const data = {
    search: $('#searchLogs').val(),
    tab: $('.nav-link.active').attr('href').replace('#','')
  };
  $.ajax({
    url: '{{ route('log-viewer.filtered') }}',
    method: 'GET',
    data: data,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    success: function(response) {
      // Replace table partials with new data
      if (data.tab === 'audit') {
        $('#audit').html(response.auditHtml);
        // Re-initialize DataTable after DOM replacement
        initAuditDataTable();
      } else {
        $('#webhook').html(response.webhookHtml);
      }
      // Show/hide empty state
      if (response.isEmpty) {
        $('#emptyState').show();
      } else {
        $('#emptyState').hide();
      }
      $('#liveUpdateSpinner').hide();
    },
    error: function() {
      $('#liveUpdateSpinner').hide();
      alert('Failed to load logs.');
    }
  });
}

function startLiveUpdates() {
  if (liveUpdateInterval) return;
  liveUpdateInterval = setInterval(applyFilters, 5000);
  $('#liveUpdateSpinner').show();
}

function stopLiveUpdates() {
  if (liveUpdateInterval) {
    clearInterval(liveUpdateInterval);
    liveUpdateInterval = null;
  }
  $('#liveUpdateSpinner').hide();
}

// Safe (idempotent) initializer for the audit table DataTable
function initAuditDataTable() {
  const selector = '#auditTable';
  if (!$.fn.DataTable) return; // plugins not loaded yet
  // If a previous instance exists (edge: re-attach), destroy before re-init
  if ($.fn.DataTable.isDataTable(selector)) {
    try { $(selector).DataTable().clear().destroy(); } catch (e) {}
  }
  $(selector).DataTable({
    responsive: true,
    lengthChange: false,
    autoWidth: false,
    ordering: true,
    info: true,
    paging: true,
    searching: true,
    pageLength: 10,
    order: [[0, 'desc']],
    // Explicitly declare 8 columns to match <thead>
    columns: [
      { defaultContent: '' }, // Time
      { defaultContent: '' }, // User
      { defaultContent: '' }, // Action
      { defaultContent: '' }, // Resource
      { defaultContent: '' }, // Tenant
      { defaultContent: '' }, // Details
      { defaultContent: '' }, // IP Address
      { defaultContent: '', orderable: false, searchable: false } // Actions
    ],
    columnDefs: [
      { targets: '_all', defaultContent: '' }
    ],
    language: {
      emptyTable: 'No audit logs available',
      zeroRecords: 'No matching audit records found',
      info: 'Showing _START_ to _END_ of _TOTAL_ audit entries',
      infoEmpty: 'Showing 0 to 0 of 0 audit entries',
      infoFiltered: '(filtered from _MAX_ total audit entries)',
      search: 'Search audit logs:',
      paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
    }
  });
}
</script>
<style>
.card {
  transition: all 0.3s ease;
}

.card:hover {
  transform: translateY(-2px);
}

.nav-tabs .nav-link {
  color: #6c757d;
  /* Light grey for inactive tabs */
  font-weight: 500;
  opacity: 0.75;
  /* Slightly dimmed when inactive */
  transition: all 0.2s ease;
}

.nav-tabs .nav-link:hover {
  color: #0d6efd;
  opacity: 0.9;
}

.nav-tabs .nav-link.active {
  color: #0d6efd;
  border-bottom: 2px solid #0d6efd;
  font-weight: 600;
  opacity: 1;
  /* Full opacity when active */
}

.table th {
  font-weight: 500;
  color: #6c757d;
}

.badge {
  font-weight: 500;
  padding: 0.5em 0.8em;
}
</style>
@endpush

@endsection