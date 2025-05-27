@php
use App\Helpers\BadgeHelper;
@endphp

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>Status</th>
        <th>Terminal</th>
        <th>Endpoint</th>
        <th>Response</th>
        <th>Duration</th>
        <th class="text-center">Actions</th>
      </tr>
    </thead>
    <tbody>
      @forelse($webhookLogs as $log)
      <tr>
        <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td>
          <span class="badge bg-{{ BadgeHelper::getStatusBadgeColor($log->status) }}">
            {{ strtoupper($log->status) }}
          </span>
        </td>
        <td class="text-nowrap">{{ $log->terminal?->terminal_uid ?? 'N/A' }}</td>
        <td class="text-nowrap">{{ Str::limit($log->endpoint, 30) }}</td>
        <td class="text-wrap" style="max-width: 300px;">
          @if($log->status === 'FAILED')
          <small class="text-danger">
            <i class="fas fa-exclamation-circle me-1"></i>
            {{ $log->error_message ?? 'Unknown error' }}
          </small>
          @else
          <small class="text-success">
            <i class="fas fa-check-circle me-1"></i>
            Success ({{ $log->http_code ?? 200 }})
          </small>
          @endif
        </td>
        <td>{{ $log->response_time ?? 0 }}ms</td>
        <td class="text-center">
          @if($log->request_payload || $log->response_payload)
          <button class="btn btn-sm btn-outline-primary" onclick="showWebhookDetails('{{ $log->id }}')">
            <i class="fas fa-search me-1"></i>Details
          </button>
          @endif
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="7" class="text-center py-4">
          <div class="text-muted">No webhook logs found</div>
        </td>
      </tr>
      @endforelse
    </tbody>
  </table>

  @if($webhookLogs->hasPages())
  <div class="mt-4">
    {{ $webhookLogs->links() }}
  </div>
  @endif
</div>