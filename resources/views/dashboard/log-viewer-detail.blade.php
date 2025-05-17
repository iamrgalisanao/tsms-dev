@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Log Details</h5>
        <div>
            <a href="{{ route('dashboard.log-viewer') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Log Viewer
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">Log Information</div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Log ID</dt>
                            <dd class="col-sm-8">{{ $log->id }}</dd>
                            
                            <dt class="col-sm-4">Log Type</dt>
                            <dd class="col-sm-8">{{ $log->log_type ?? 'general' }}</dd>
                            
                            <dt class="col-sm-4">Severity</dt>
                            <dd class="col-sm-8">
                                <span class="badge 
                                    @if($log->severity == 'error') bg-danger 
                                    @elseif($log->severity == 'warning') bg-warning 
                                    @elseif($log->severity == 'info') bg-info 
                                    @else bg-secondary @endif">
                                    {{ $log->severity ?? 'info' }}
                                </span>
                            </dd>
                            
                            <dt class="col-sm-4">Created At</dt>
                            <dd class="col-sm-8">{{ $log->created_at->format('Y-m-d H:i:s') }}</dd>
                            
                            <dt class="col-sm-4">Updated At</dt>
                            <dd class="col-sm-8">{{ $log->updated_at->format('Y-m-d H:i:s') }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">Related Information</div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Transaction ID</dt>
                            <dd class="col-sm-8">{{ $log->transaction_id ?? 'N/A' }}</dd>
                            
                            <dt class="col-sm-4">Terminal</dt>
                            <dd class="col-sm-8">{{ $log->posTerminal->terminal_uid ?? 'N/A' }}</dd>
                            
                            <dt class="col-sm-4">User</dt>
                            <dd class="col-sm-8">{{ $log->user ? ($log->user->name . ' (' . $log->user->email . ')') : 'N/A' }}</dd>
                            
                            <dt class="col-sm-4">Tenant</dt>
                            <dd class="col-sm-8">{{ $log->tenant->name ?? 'N/A' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">Message</div>
            <div class="card-body">
                <p>{{ $log->message ?? 'No message' }}</p>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">Context</div>
            <div class="card-body">
                <pre class="bg-light p-3 border rounded">@if($log->context)
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
@endif</pre>
            </div>
        </div>
    </div>
</div>
@endsection
