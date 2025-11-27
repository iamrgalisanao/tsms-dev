@extends('layouts.master')
@section('title', 'Weekday Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Weekday Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Weekday report UI goes here. Filter by weekdays and provide export options.</div>
  </div>
</div>
@endsection
