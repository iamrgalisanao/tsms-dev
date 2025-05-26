<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>User</th>
        <th>Action</th>
        <th>Details</th>
        <th>IP Address</th>
        <th class="text-center">Context</th>
      </tr>
    </thead>
    <tbody>
      @forelse($auditLogs as $log)
      <tr>
        <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td>{{ $log->user?->name ?? 'System' }}</td>
        <td>
          <span class="badge bg-{{ LogHelper::getLogTypeClass($log->action) }}">
            {{ ucfirst($log->action) }}
          </span>
        </td>
        <td class="text-wrap" style="max-width: 300px;">
          <small class="text-muted">{{ $log->message }}</small>
        </td>
        <td class="text-nowrap">{{ $log->ip_address ?? 'N/A' }}</td>
        <td class="text-center">
          @if($log->context)
          <button class="btn btn-sm btn-outline-info" onclick="showContext('{{ $log->id }}')">
            <i class="fas fa-info-circle"></i>
          </button>
          @endif
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="6" class="text-center">No audit logs found</td>
      </tr>
      @endforelse
    </tbody>
  </table>
</div>