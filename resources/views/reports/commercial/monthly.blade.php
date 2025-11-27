@extends('layouts.master')
@section('title', 'Monthly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Monthly Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Monthly report UI goes here. Use month picker and include export/print actions.</div>
  </div>
</div>
@endsection
