@php
use App\Helpers\BadgeHelper;
@endphp

<tr>
  <td class="text-nowrap">{{ $log->transaction_id }}</td>
  <td class="text-nowrap">{{ $log->terminal->terminal_uid ?? 'N/A' }}</td>
  <td>{{ number_format($log->amount, 2) }}</td>
  <td>{!! BadgeHelper::getValidationStatusBadge($log->validation_status ?: 'PENDING') !!}</td>
  <td>{!! BadgeHelper::getJobStatusBadge($log->job_status) !!}</td>
  <td class="text-center">{{ $log->job_attempts }}</td>
  <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
  <td>
    <a href="{{ route('transactions.logs.show', $log->id) }}" class="btn btn-sm btn-info">
      <i class="fas fa-eye"></i>
    </a>
  </td>
</tr>