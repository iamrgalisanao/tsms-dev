@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>Type</th>
        <th>Severity</th>
        <th>Terminal</th>
        <th>Message</th>
        <th>Transaction</th>
        <th class="text-center">Actions</th>
      </tr>
    </thead>
    <tbody>
      @forelse($systemLogs as $log)
      <tr>
        <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td>
          <span class="badge bg-{{ LogHelper::getLogTypeClass($log->log_type) }}">
            {{ ucfirst($log->log_type) }}
          </span>
        </td>
        <td>
          <span class="badge bg-{{ BadgeHelper::getStatusBadgeColor($log->severity) }}">
            {{ strtoupper($log->severity) }}
          </span>
        </td>
        <td class="text-nowrap">{{ $log->terminal_uid ?? 'N/A' }}</td>
        <td class="text-wrap" style="max-width: 300px;">
          <small class="text-muted">{{ $log->message }}</small>
        </td>
        <td class="text-nowrap">
          @if($log->transaction_id)
          <a href="{{ route('transactions.show', $log->transaction_id) }}"
            class="btn btn-sm btn-link text-decoration-none">
            {{ $log->transaction_id }}
          </a>
          @else
          <span class="text-muted">N/A</span>
          @endif
        </td>
        <td class="text-center">
          @if($log->context)
          <button class="btn btn-sm btn-outline-primary" onclick="showContext('{{ $log->id }}')">
            <i class="fas fa-search me-1"></i>Details
          </button>
          @endif
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="7" class="text-center py-4">
          <div class="text-muted">
            <i class="fas fa-info-circle me-1"></i>No system logs found
          </div>
        </td>
      </tr>
      @endforelse
    </tbody>
  </table>

  @if($systemLogs->hasPages())
  <div class="mt-4">
    {{ $systemLogs->links() }}
  </div>
  @endif
</div>