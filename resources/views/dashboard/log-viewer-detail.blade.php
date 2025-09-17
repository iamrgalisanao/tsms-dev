@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="text-gray-900 mb-1">Log Details</h4>
      <p class="text-muted mb-0">Log ID: {{ $log->id }}</p>
    </div>
    <a href="{{ route('log-viewer.index') }}" class="btn btn-outline-secondary">
      <i class="fas fa-arrow-left me-2"></i> Back to Logs
    </a>
  </div>

  <div class="row g-4">
    <!-- Main Info Card -->
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-bottom-0 pt-4">
          <h5 class="mb-0 text-primary">Log Information</h5>
        </div>
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <span class="badge rounded-pill bg-{{ 
                            $log->severity == 'error' ? 'danger' : 
                            ($log->severity == 'warning' ? 'warning' : 
                            ($log->severity == 'info' ? 'info' : 'secondary')) 
                        }} bg-opacity-10 text-{{ 
                            $log->severity == 'error' ? 'danger' : 
                            ($log->severity == 'warning' ? 'warning' : 
                            ($log->severity == 'info' ? 'info' : 'secondary')) 
                        }} px-3 py-2">
              <i class="fas fa-{{ 
                                $log->severity == 'error' ? 'exclamation-circle' : 
                                ($log->severity == 'warning' ? 'exclamation-triangle' : 'info-circle') 
                            }} me-2"></i>
              {{ ucfirst($log->severity ?? 'info') }}
            </span>
          </div>
          <dl class="row">
            <dt class="col-sm-4">Log Type</dt>
            <dd class="col-sm-8">{{ $log->log_type ?? 'general' }}</dd>

            <dt class="col-sm-4">Created At</dt>
            <dd class="col-sm-8">{{ $log->created_at->format('Y-m-d H:i:s') }}</dd>

            <dt class="col-sm-4">Updated At</dt>
            <dd class="col-sm-8">{{ $log->updated_at->format('Y-m-d H:i:s') }}</dd>
          </dl>
        </div>
      </div>
    </div>

    <!-- Context Card -->
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-bottom-0 pt-4">
          <h5 class="mb-0 text-primary">Related Information</h5>
        </div>
        <div class="card-body">
          <dl class="row">
            <dt class="col-sm-4">Transaction ID</dt>
            <dd class="col-sm-8">{{ $log->transaction_id ?? 'N/A' }}</dd>

            <dt class="col-sm-4">Terminal</dt>
            <dd class="col-sm-8">{{ $log->posTerminal->terminal_uid ?? 'N/A' }}</dd>

            <dt class="col-sm-4">User</dt>
            <dd class="col-sm-8">{{ $log->user ? ($log->user->name . ' (' . $log->user->email . ')') : 'N/A' }}</dd>

            <dt class="col-sm-4">Tenant</dt>
            <dd class="col-sm-8">{{ $log->tenant->trade_name ?? 'N/A' }}</dd>
          </dl>
        </div>
      </div>
    </div>

    <!-- Message Card -->
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-bottom-0 pt-4">
          <h5 class="mb-0 text-primary">Message</h5>
        </div>
        <div class="card-body">
          <div class="p-4 bg-light rounded-3 border border-1">
            {{ $log->message ?? 'No message available' }}
          </div>
        </div>
      </div>
    </div>

    <!-- Context Data Card -->
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-bottom-0 pt-4">
          <h5 class="mb-0 text-primary">Context Data</h5>
        </div>
        <div class="card-body">
          <pre class="p-4 bg-light rounded-3 border border-1 mb-0"><code>@if($log->context)
@if(is_string($log->context))
@php
    try {
        $context = json_decode($log->context, true);
        echo json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } catch (\Exception $e) {
        echo $log->context;
    }
@endphp
@else
{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
@endif
@else
No context data available
@endif</code></pre>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
.card {
  border-radius: 0.75rem;
}

.badge {
  font-weight: 500;
}

.text-primary {
  color: #4f46e5 !important;
}

pre {
  background-color: #f8fafc;
  margin: 0;
}

code {
  color: #334155;
}

.shadow-sm {
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, .1), 0 1px 2px -1px rgba(0, 0, 0, .1) !important;
}
</style>
@endpush