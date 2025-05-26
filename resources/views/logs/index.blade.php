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
        <h4 class="mb-0 text-dark">System Logs Dashboard</h4>
        <div class="d-flex gap-3">
          <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
              <i class="fas fa-download me-2"></i>Export
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'csv']) }}">CSV</a></li>
              <li><a class="dropdown-item" href="{{ route('logs.export', ['format' => 'pdf']) }}">PDF</a></li>
            </ul>
          </div>
          <div class="form-check form-switch d-flex align-items-center">
            <input class="form-check-input me-2" type="checkbox" id="liveUpdate" checked>
            <label class="form-check-label text-muted" for="liveUpdate">Live Updates</label>
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
      <!-- Search and Filters -->
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="input-group">
            <input type="text" class="form-control" id="searchLogs" placeholder="Search logs...">
            <button class="btn btn-primary px-4" onclick="applyFilters()">
              <i class="fas fa-search me-2"></i>Search
            </button>
          </div>
        </div>
        <div class="col-md-6 text-end">
          <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
            data-bs-target="#advancedFilters">
            <i class="fas fa-filter me-2"></i>Advanced Filters
          </button>
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
      </div>
    </div>
  </div>
</div>

@include('logs.partials.context-modal')

@push('styles')
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