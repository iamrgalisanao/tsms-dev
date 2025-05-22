<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th class="ps-3">Transaction ID</th>
        <th>Terminal</th>
        <th class="text-end">Amount</th>
        <th class="text-center">Validation</th>
        <th class="text-center">Status</th>
        <th class="text-center">Attempts</th>
        <th>Created At</th>
        <th class="text-end pe-3">Actions</th>
      </tr>
    </thead>
    <tbody>
      @forelse($transactions as $transaction)
      <tr>
        <td class="ps-3">{{ $transaction->transaction_id }}</td>
        <td>{{ $transaction->terminal->identifier ?? 'N/A' }}</td>
        <td class="text-end">₱{{ number_format($transaction->gross_sales, 2) }}</td>
        <td class="text-center">
          <span
            class="badge bg-{{ $transaction->validation_status === 'VALID' ? 'success' : ($transaction->validation_status === 'ERROR' ? 'danger' : 'warning') }}">
            {{ $transaction->validation_status }}
          </span>
        </td>
        <td class="text-center">
          <span
            class="badge bg-{{ $transaction->job_status === 'COMPLETED' ? 'success' : ($transaction->job_status === 'FAILED' ? 'danger' : 'info') }}">
            {{ $transaction->job_status }}
          </span>
        </td>
        <td class="text-center">{{ $transaction->job_attempts }}</td>
        <td>{{ $transaction->created_at->format('M d, Y h:i A') }}</td>
        <td class="text-end pe-3">
          <div class="d-flex gap-2 justify-content-end">
            <a href="{{ route('transactions.show', $transaction->id) }}" class="btn btn-sm btn-primary">
              <i class="fas fa-eye"></i> View Details
            </a>
            @if($transaction->validation_status === 'ERROR')
            <button type="button" class="btn btn-sm btn-warning retry-transaction" data-id="{{ $transaction->id }}">
              <i class="fas fa-sync-alt"></i> Retry
            </button>
            @endif
          </div>
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="8" class="text-center py-4">No transactions found</td>
      </tr>
      @endforelse
    </tbody>
  </table>
</div>

@if(isset($transactions) && method_exists($transactions, 'hasPages') && $transactions->hasPages())
<div class="d-flex justify-content-end mt-3">
  {{ $transactions->links() }}
</div>
@endif