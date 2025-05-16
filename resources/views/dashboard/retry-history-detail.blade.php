@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5>Retry Details</h5>
    <div>
      <a href="{{ route('dashboard.retry-history') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Retry History
      </a>
    </div>
  </div>

  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <div class="card mb-4">
          <div class="card-header">Transaction Information</div>
          <div class="card-body">
            <dl class="row">
              <dt class="col-sm-4">Transaction ID</dt>
              <dd class="col-sm-8">{{ $log->transaction_id }}</dd>

              <dt class="col-sm-4">Terminal UID</dt>
              <dd class="col-sm-8">{{ $log->posTerminal->terminal_uid ?? 'Unknown' }}</dd>

              <dt class="col-sm-4">Tenant</dt>
              <dd class="col-sm-8">{{ $log->tenant->name ?? 'Unknown' }}</dd>

              <dt class="col-sm-4">Status</dt>
              <dd class="col-sm-8">
                <span
                  class="badge @if($log->status == 'SUCCESS') bg-success @elseif($log->status == 'FAILED') bg-danger @else bg-warning @endif">
                  {{ $log->status }}
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
          <div class="card-header">Retry Information</div>
          <div class="card-body">
            <dl class="row">
              <dt class="col-sm-4">Retry Count</dt>
              <dd class="col-sm-8">{{ $log->retry_count }}</dd>

              <dt class="col-sm-4">Last Retry</dt>
              <dd class="col-sm-8">{{ $log->last_retry_at ? $log->last_retry_at->format('Y-m-d H:i:s') : 'N/A' }}</dd>

              <dt class="col-sm-4">Response Time</dt>
              <dd class="col-sm-8">{{ $log->response_time ? round($log->response_time, 2) . 'ms' : 'N/A' }}</dd>

              <dt class="col-sm-4">Retry Success</dt>
              <dd class="col-sm-8">
                @if($log->retry_success === true)
                <span class="badge bg-success">Yes</span>
                @elseif($log->retry_success === false)
                <span class="badge bg-danger">No</span>
                @else
                <span class="badge bg-secondary">Unknown</span>
                @endif
              </dd>

              <dt class="col-sm-4">Retry Reason</dt>
              <dd class="col-sm-8">{{ $log->retry_reason ?? 'No reason provided' }}</dd>
            </dl>

            <div class="mt-3">
              <form action="{{ route('dashboard.retry-history.retry', $log->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-arrow-repeat"></i> Retry Again
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Retry Attempt Timeline</div>
      <div class="card-body">
        <div class="timeline">
          <!-- This would be populated with actual retry attempt data from a related retry_attempts table -->
          <!-- For now, using a placeholder message -->
          <p class="text-muted">Detailed retry attempt timeline data will be available in a future update.</p>

          <!-- Example of what the timeline might look like with real data -->
          <div class="timeline-example">
            <ul class="list-group">
              <li class="list-group-item d-flex justify-content-between align-items-start">
                <div class="ms-2 me-auto">
                  <div class="fw-bold">Initial Attempt</div>
                  Transaction created
                </div>
                <span class="badge bg-primary rounded-pill">{{ $log->created_at->format('Y-m-d H:i:s') }}</span>
              </li>

              @if($log->retry_count > 0)
              <li class="list-group-item d-flex justify-content-between align-items-start">
                <div class="ms-2 me-auto">
                  <div class="fw-bold">Retry Attempt #1</div>
                  {{ $log->retry_reason ?? 'Automatic retry' }}
                </div>
                <span
                  class="badge bg-primary rounded-pill">{{ $log->last_retry_at ? $log->last_retry_at->format('Y-m-d H:i:s') : 'Unknown' }}</span>
              </li>
              @endif
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection