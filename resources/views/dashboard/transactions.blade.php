@extends('layouts.app')

@section('content')
<div class="card mt-4">
  <div class="card-header">
    <h5>Transactions</h5>
  </div>
  <div class="card-body">
    <!-- Filters -->
    <div class="mb-4">
      <form method="GET" action="{{ route('transactions') }}" class="row g-3">
        <div class="col-md-3">
          <label for="validation_status" class="form-label">Status</label>
          <select name="validation_status" id="validation_status" class="form-select">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
            <option value="{{ $status }}" {{ request('validation_status') == $status ? 'selected' : '' }}>
              {{ strtoupper($status) }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-3">
          <label for="terminal_id" class="form-label">Terminal</label>
          <select name="terminal_id" id="terminal_id" class="form-select">
            <option value="">All Terminals</option>
            @foreach($terminals as $terminal)
            <option value="{{ $terminal->id }}" {{ request('terminal_id') == $terminal->id ? 'selected' : '' }}>
              {{ $terminal->terminal_uid }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-2">
          <label for="date_from" class="form-label">Date From</label>
          <input type="date" name="date_from" id="date_from" value="{{ request('date_from') }}" class="form-control">
        </div>

        <div class="col-md-2">
          <label for="date_to" class="form-label">Date To</label>
          <input type="date" name="date_to" id="date_to" value="{{ request('date_to') }}" class="form-control">
        </div>

        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary me-2">Filter</button>
          <a href="{{ route('transactions') }}" class="btn btn-secondary">Reset</a>
        </div>
      </form>
    </div>

    <!-- Transactions Table -->
    <div class="table-responsive">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Terminal</th>
            <th>Amount</th>
            <th>Date</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($transactions as $transaction)
          <tr>
            <td>{{ $transaction->transaction_id }}</td>
            <td>{{ $transaction->serial_number }}</td>
            {{-- <td>{{ $transaction->posTerminal->terminal_uid ?? 'Unknown' }}</td> --}}
            <td>{{ number_format($transaction->base_amount, 2) }}</td>
            <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
            <td>
              <span class="badge {{ $transaction->validation_status === 'valid' ? 'bg-success' : 
                 ($transaction->validation_status === 'invalid' ? 'bg-danger' : 'bg-warning') }}">
                {{ strtoupper($transaction->validation_status ?? 'PENDING') }}
              </span>
            </td>
            <td>
              <a href="{{ route('transactions') }}/{{ $transaction->id }}" class="btn btn-sm btn-info">View</a>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="6" class="text-center">No transactions found</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4">
      {{ $transactions->withQueryString()->links() }}
    </div>
  </div>
</div>
@endsection