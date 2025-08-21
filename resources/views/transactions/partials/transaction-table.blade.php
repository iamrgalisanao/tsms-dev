<div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Customer Code</th>
        <th class="ps-3">Transaction ID</th>
        <th>Terminals</th>
        <th class="text-end">Amount</th>
        <th class="text-center">Validation</th>
        <th class="text-center">Status</th>
        <!-- <th class="text-center">Attempts</th> -->
        <!-- <th class="text-center">Transaction Count</th> -->
        <th class="text-center">Created At</th>
        <!-- <th class="text-end pe-3">Actions</th> -->
      </tr>
    </thead>
    <tbody>
      @forelse($transactions as $transaction)
      <tr>
        <td>{{ $transaction->customer_code }}</td>
        <td class="ps-3">{{ $transaction->transaction_id }}</td>
        <td>{{ $transaction->terminal->id ?? 'N/A' }}</td>
        <td class="text-end">₱{{ number_format($transaction->base_amount, 2) }}</td>
        <td class="text-center">
          <span class="badge bg-{{
            $transaction->validation_status === 'VALID' ? 'success' :
            ($transaction->validation_status === 'ERROR' ? 'danger' :
            ($transaction->validation_status === null || $transaction->validation_status === '' || $transaction->validation_status === 'PENDING' ? 'warning' : 'secondary'))
          }}">
            {{ $transaction->validation_status ?: 'PENDING' }}
          </span>
        </td>
        <td class="text-center">
          <span
            class="badge bg-{{ $transaction->latest_job_status === 'COMPLETED' ? 'success' : ($transaction->latest_job_status === 'FAILED' ? 'danger' : 'info') }}">
            {{ $transaction->latest_job_status ?? 'QUEUED' }}
          </span>
        </td>
        <!-- <td class="text-center">{{ $transaction->job_attempts }}</td>q -->
        <!-- <td class="text-center">{{ $transaction->transaction_count }}</td> -->
        <td class="text-center">{{ $transaction->created_at->format('M d, Y h:i A') }}</td>
        <!-- <td class=" text-end pe-3">
          <div class="d-flex gap-2 justify-content-end">
            <a href="{{ route('transactions.logs.show', $transaction->id) }}" class="btn btn-sm btn-primary">
              <i class="fas fa-eye"></i> View Details
            </a>
            @if($transaction->validation_status === 'ERROR')
            <button type="button" class="btn btn-sm btn-warning retry-transaction" data-id="{{ $transaction->id }}">
              <i class="fas fa-sync-alt"></i> Retry
            </button>
            @endif
          </div>
        </td> -->
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
<div class="d-flex justify-content-between align-items-center border-top pt-3 px-3">
  <div class="text-muted small">
    Showing {{ $transactions->firstItem() }} to {{ $transactions->lastItem() }} of {{ $transactions->total() }} entries
  </div>
  <nav>
    {{ $transactions->links('pagination::bootstrap-5')->withQueryString() }}
  </nav>
</div>
@endif