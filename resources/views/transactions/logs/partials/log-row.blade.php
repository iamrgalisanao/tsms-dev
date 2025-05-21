<tr>
    <td>{{ $log->transaction_id }}</td>
    <td>{{ $log->terminal->identifier ?? 'N/A' }}</td>
    <td>{{ number_format($log->gross_sales, 2) }}</td>
    <td>
        <span class="badge bg-{{ $log->validation_status === 'VALID' ? 'success' : ($log->validation_status === 'ERROR' ? 'danger' : 'warning') }}">
            {{ $log->validation_status }}
        </span>
    </td>
    <td>
        <span class="badge bg-{{ $log->job_status === 'COMPLETED' ? 'success' : ($log->job_status === 'FAILED' ? 'danger' : 'info') }}">
            {{ $log->job_status }}
        </span>
    </td>
    <td>{{ $log->job_attempts }}</td>
    <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
    <td>
        <a href="{{ route('transactions.logs.show', $log->id) }}" class="btn btn-sm btn-info">
            <i class="fas fa-eye"></i>
        </a>
    </td>
</tr>
