<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>Status</th>
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
        <td class="text-nowrap">{{ $log->endpoint }}</td>
        <td class="text-wrap" style="max-width: 300px;">
          <small class="text-muted">
            {{ $log->status === 'FAILED' ? $log->error_message : 'Success' }}
          </small>
        </td>
        <td>{{ $log->response_time }}ms</td>
        <td class="text-center">
          <button class="btn btn-sm btn-outline-primary" onclick="showContext('{{ $log->id }}')">
            <i class="fas fa-search me-1"></i>Details
          </button>
        </td>
      </tr>
      @empty
      <tr><td colspan="6" class="text-center">No webhook logs found</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
