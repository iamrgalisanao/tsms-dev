@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5>Webhook Logs</h5>
      <div class="d-flex gap-2">
        <div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="fas fa-download me-1"></i>Export
          </button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item"
                href="{{ route('logs.export', ['type' => 'webhook', 'format' => 'csv']) }}">CSV</a></li>
            <li><a class="dropdown-item"
                href="{{ route('logs.export', ['type' => 'webhook', 'format' => 'pdf']) }}">PDF</a></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Time</th>
            <th>Terminal</th>
            <th>Status</th>
            <th>Response</th>
            <th>Retries</th>
            <th>Next Retry</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $log)
          <tr>
            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
            <td>{{ $log->terminal->terminal_uid ?? 'N/A' }}</td>
            <td><span class="badge bg-{{ $log->status === 'SUCCESS' ? 'success' : 'danger' }}">{{ $log->status }}</span>
            </td>
            <td>{{ Str::limit($log->response_payload, 50) }}</td>
            <td>{{ $log->retry_count }}/{{ $log->max_retries }}</td>
            <td>{{ $log->next_retry_at ? $log->next_retry_at->format('Y-m-d H:i:s') : 'N/A' }}</td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick="showDetails('{{ $log->id }}')">
                <i class="fas fa-search me-1"></i>Details
              </button>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="7" class="text-center">No webhook logs found</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

@push('scripts')
<script>
function showDetails(id) {
  fetch(`/api/logs/${id}/context`)
    .then(response => response.json())
    .then(data => {
      const modal = new bootstrap.Modal(document.getElementById('contextModal'));
      document.getElementById('contextContent').textContent = JSON.stringify(data, null, 2);
      modal.show();
    });
}
</script>
@endpush
@endsection