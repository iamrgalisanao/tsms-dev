@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="row">
    <!-- Filters -->
    <div class="col-12 mb-4">
      <div class="card">
        <div class="card-body">
          <form id="filterForm" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Date Range</label>
              <select class="form-select" name="dateRange">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="custom">Custom Range</option>
              </select>
            </div>
            <div class="col-md-6" id="customDateRange" style="display: none;">
              <div class="row">
                <div class="col-md-6">
                  <label class="form-label">Start Date</label>
                  <input type="date" class="form-control" name="startDate">
                </div>
                <div class="col-md-6">
                  <label class="form-label">End Date</label>
                  <input type="date" class="form-control" name="endDate">
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Export Format</label>
              <select class="form-select" name="exportFormat">
                <option value="csv">CSV</option>
                <option value="pdf">PDF</option>
                <option value="excel">Excel</option>
              </select>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Performance Charts -->
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Performance Metrics</h5>
          <div class="btn-group">
            <button type="button" class="btn btn-primary" id="exportBtn">
              <i class="fas fa-download me-2"></i> Export
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="row">
            <!-- Chart containers -->
            <div class="col-md-6 mb-4">
              <canvas id="successRateChart"></canvas>
            </div>
            <div class="col-md-6 mb-4">
              <canvas id="processingTimeChart"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Performance dashboard scripts
document.addEventListener('DOMContentLoaded', function() {
  // Chart initialization and update logic
});
</script>
@endpush