@extends('layouts.master')
@section('title', 'Daily Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Daily Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Daily report UI goes here. Load summary and per-day breakdown; provide date selector and export controls.</div>
  </div>
</div>
@endsection
