@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>User</th>
        <th>Action</th>
        <th>Resource</th>
        <th>Details</th>
        <th class="text-center">IP Address</th>
        <th class="text-center">Actions</th>
      </tr>
    </thead>
    <tbody>
      @forelse($auditLogs as $log)
      <tr>
        <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td>{{ $log->user?->name ?? 'System' }}</td>
        <td>
          <span class="badge bg-{{ LogHelper::getActionTypeClass($log->action_type) }}">
            @if(str_starts_with($log->action, 'auth.'))
            @switch($log->action)
            @case('auth.login')
            <i class="fas fa-sign-in-alt me-1"></i>Login
            @break
            @case('auth.logout')
            <i class="fas fa-sign-out-alt me-1"></i>Logout
            @break
            @case('auth.failed')
            <i class="fas fa-exclamation-triangle me-1"></i>Failed Login
            @break
            @default
            {{ $log->action }}
            @endswitch
            @else
            {{ $log->action }}
            @endif
          </span>
        </td>
        <td>{{ $log->resource_type }}</td>
        <td class="text-wrap" style="max-width: 300px;">
          <small class="text-muted">{{ $log->message }}</small>
        </td>
        <td class="text-center">{{ $log->ip_address }}</td>
        <td class="text-center">
          @if($log->metadata || $log->old_values)
          <button class="btn btn-sm btn-outline-primary" onclick="showContext('{{ $log->id }}')">
            <i class="fas fa-search me-1"></i>Details
          </button>
          @endif
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="7" class="text-center py-4">
          <div class="text-muted">No audit logs found</div>
        </td>
      </tr>
      @endforelse
    </tbody>
  </table>

  @if($auditLogs->hasPages())
  <div class="d-flex justify-content-between align-items-center mt-4">
    <div class="pagination-info">
      Showing {{ $auditLogs->firstItem() }} to {{ $auditLogs->lastItem() }} of {{ $auditLogs->total() }} entries
    </div>
    {{ $auditLogs->links() }}
  </div>
  @endif
</div>