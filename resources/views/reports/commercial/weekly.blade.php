@extends('layouts.master')
@section('title', 'Weekly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Weekly Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Weekly report UI goes here. Include week range selector and aggregated metrics.</div>
  </div>
</div>
@endsection
