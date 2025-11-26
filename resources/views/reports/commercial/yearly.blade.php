@extends('layouts.master')
@section('title', 'Yearly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Yearly Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Yearly report UI goes here. Include year selector and aggregated summaries per month.</div>
  </div>
</div>
@endsection
