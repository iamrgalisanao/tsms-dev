@extends('layouts.master')

@section('content')
<div class="container-fluid">
  <div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h3 class="m-0">Commercial Dashboard</h3>
      <div>
        <small class="text-muted">Showing aggregated sales for all tenants</small>
      </div>
    </div>
  </div>

  <div class="row mb-3">
    @include('components.adminlte-chart', ['id' => 'chart-daily', 'key' => 'daily', 'title' => 'Daily Sales'])
    @include('components.adminlte-chart', ['id' => 'chart-weekly', 'key' => 'weekly', 'title' => 'Weekly Sales'])
  </div>

  <div class="row">
    @include('components.adminlte-chart', ['id' => 'chart-monthly', 'key' => 'monthly', 'title' => 'Monthly Sales'])
    @include('components.adminlte-chart', ['id' => 'chart-yearly', 'key' => 'yearly', 'title' => 'Yearly Sales'])
  </div>
</div>

@endsection

@push('scripts')
  @include('reports.commercial._dashboard_scripts')
@endpush
