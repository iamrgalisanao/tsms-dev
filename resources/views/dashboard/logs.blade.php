@extends('layouts.master')
@section('title', 'System Logs')

@push('styles')
<!-- DataTables -->
<link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/toastr/toastr.min.css') }}">

@endpush

@section('content')

<div class="container-fluid py-4">
  <!-- Stats Cards Row -->
  <div class="row g-4 mb-4">
     
    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-flag"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Total Events</span>
          <span class="info-box-number">{{ number_format($stats['total'] ?? 0) }}</span>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-key"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Auth Events</span>
          <span class="info-box-number">{{ number_format($stats['auth'] ?? 0) }}</span>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-clock"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Changes</span>
          <span class="info-box-number">{{ number_format($stats['changes'] ?? 0) }}</span>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-thumbs-down"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Error Logs</span>
          <span class="info-box-number">{{ number_format($stats['error_logs'] ?? 0) }}</span>
        </div>
      </div>
    </div>
  </div>

    
    <!-- Add Webhook Stats Card -->
    {{-- <div class="col-sm-6 col-xl-3">
      <div class="card h-100 border-0 shadow-sm hover-lift">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="flex-shrink-0 rounded-3 p-3 bg-info bg-opacity-10">
              <i class="fas fa-exchange-alt fa-2x text-info"></i>
            </div>
            <div class="flex-grow-1 ms-3">
              <h3 class="mb-1">{{ number_format($stats['webhook_total'] ?? 0) }}</h3>
              <div class="text-muted small">Webhook Events</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div> --}}

  <!-- Main Content Card -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white p-4 border-bottom">
      <div class="d-flex justify-content-between align-items-center">
        <ul class="nav nav-pills" role="tablist">
          <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#audit">
              <i class="fas fa-history me-2"></i> Audit Trail
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#system">
              <i class="fas fa-cogs me-2"></i> System Logs
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#webhook">
              <i class="fas fa-exchange-alt me-2"></i> Webhook Logs
            </a>
          </li>
        </ul>
        {{-- <div class="d-flex gap-3">
          <div class="dropdown">
            <button class="btn btn-outline-primary rounded-pill dropdown-toggle" data-bs-toggle="dropdown">
              <i class="fas fa-download me-2"></i>Export
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'csv']) }}">CSV</a></li>
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'pdf']) }}">PDF</a></li>
            </ul>
          </div>
          <div class="form-check form-switch d-flex align-items-center">
            <input class="form-check-input me-2" type="checkbox" id="liveUpdate" checked>
            <label class="form-check-label ">Live Updates</label>
          </div>
        </div> --}}
      </div>
    </div>

    <div class="card-body p-4">
      <!-- Search and Filters -->
      {{-- <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0">
              <i class="fas fa-search "></i>
            </span>
            <input type="text" class="form-control border-start-0 ps-0" id="searchLogs" placeholder="Search logs...">
          </div>
        </div>
        <div class="col-md-6 text-end">
          <button class="btn btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters">
            <i class="fas fa-filter me-2"></i>Advanced Filters
          </button>
        </div>
      </div> --}}

      <!-- Advanced Filters -->
      {{-- <div class="collapse mb-4" id="advancedFilters">
        <div class="card card-body bg-light">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Log Type</label>
              <select class="form-select" id="logType">
                <option value="">All Types</option>
                <option value="system">System</option>
                <option value="audit">Audit</option>
                <option value="webhook">Webhook</option>
                <option value="error">Error</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Severity</label>
              <select class="form-select" id="severity">
                <option value="">All Severities</option>
                <option value="error">Error</option>
                <option value="warning">Warning</option>
                <option value="info">Info</option>
                <option value="debug">Debug</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Date Range</label>
              <div class="input-group">
                <input type="date" class="form-control" id="dateFrom">
                <input type="date" class="form-control" id="dateTo">
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Terminal</label>
              <select class="form-select" id="terminalFilter">
                <option value="">All Terminals</option>
                @foreach($terminals ?? [] as $terminal)
                <option value="{{ $terminal->id }}">{{ $terminal->terminal_uid }}</option>
                @endforeach
              </select>
            </div>
          </div>
        </div>
      </div> --}}

      <!-- Tab Content -->
      <div class="tab-content">
        <div class="tab-pane fade" id="system">
          @include('logs.partials.system-table', ['logs' => $systemLogs])
        </div>
        <div class="tab-pane fade show active" id="audit">
          @include('logs.partials.audit-table', ['logs' => $auditLogs])
        </div>
        <div class="tab-pane fade" id="webhook">
          @include('logs.partials.webhook-table', ['logs' => $webhookLogs])
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
   
function applyFilters() {
  const filters = {
    search: document.getElementById('searchLogs').value,
    type: document.getElementById('logType').value,
    severity: document.getElementById('severity').value,
    date_from: document.getElementById('dateFrom').value,
    date_to: document.getElementById('dateTo').value,
    terminal: document.getElementById('terminalFilter').value
  };

  const params = new URLSearchParams(filters);
  window.location.href = `${window.location.pathname}?${params.toString()}`;
}
</script>


@endpush


