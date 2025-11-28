@extends('layouts.master')
@section('title', 'Tenant Details')
@section('content')
<div class="card">
  <div class="card-header bg-primary d-flex justify-content-between align-items-center">
    <h3 class="card-title text-white">Tenant Details</h3>
    <a href="{{ route('commercial.sales-report.tenants') }}" class="btn btn-light btn-sm">Back to list</a>
  </div>
  <div class="card-body">
    <dl class="row">
      <dt class="col-sm-3">Tenant ID</dt>
      <dd class="col-sm-9">{{ $tenant->id }}</dd>

      <dt class="col-sm-3">Tenant Code</dt>
      <dd class="col-sm-9">{{ $tenant->customer_code }}</dd>

      <dt class="col-sm-3">Trade Name</dt>
      <dd class="col-sm-9">{{ $tenant->trade_name }}</dd>

      <dt class="col-sm-3">Location Type</dt>
      <dd class="col-sm-9">{{ $tenant->location_type ?? '' }}</dd>

      <dt class="col-sm-3">Unit No.</dt>
      <dd class="col-sm-9">{{ $tenant->unit_no ?? '' }}</dd>

      <dt class="col-sm-3">Level</dt>
      <dd class="col-sm-9">{{ $tenant->level ?? '' }}</dd>

      <dt class="col-sm-3">Category</dt>
      <dd class="col-sm-9">{{ $tenant->category ?? '' }}</dd>

      <dt class="col-sm-3">Zone</dt>
      <dd class="col-sm-9">{{ $tenant->zone ?? '' }}</dd>

      <dt class="col-sm-3">Terminals</dt>
      <dd class="col-sm-9">{{ $tenant->posTerminals->count() }} terminals</dd>
    </dl>

    <h5>Terminals</h5>
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead>
          <tr>
            <th>ID</th>
            <th>Terminal Code</th>
            <th>Type</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach($tenant->posTerminals as $terminal)
            <tr>
              <td>{{ $terminal->id }}</td>
              <td>{{ $terminal->terminal_code ?? '' }}</td>
              <td>{{ $terminal->type ?? '' }}</td>
              <td>{{ $terminal->status ?? '' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>

@endsection
