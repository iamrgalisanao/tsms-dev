@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-12 mb-3">
      <a href="{{ route('transactions.test') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left mr-1"></i> Back to Test Transactions
      </a>
    </div>

    <div class="col-md-12">
      <div class="card">
        <div class="card-header">
          <h5>Transaction Details: {{ $transaction->transaction_id }}</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <h6>Basic Information</h6>
              <table class="table table-sm">
                <tr>
                  <th width="150">Transaction ID</th>
                  <td>{{ $transaction->transaction_id }}</td>
                </tr>
                <tr>
                  <th>Terminal ID</th>
                  <td>{{ $transaction->terminal_id }}</td>
                </tr>
                <tr>
                  <th>Date/Time</th>
                  <td>{{ $transaction->transaction_timestamp }}</td>
                </tr>
                <tr>
                  <th>Status</th>
                  <td>
                    <span
                      class="badge bg-{{ $transaction->job_status === 'COMPLETED' ? 'success' : ($transaction->job_status === 'FAILED' ? 'danger' : 'warning') }}">
                      {{ $transaction->job_status }}
                    </span>
                  </td>
                </tr>
                <tr>
                  <th>Validation</th>
                  <td>
                    <span
                      class="badge bg-{{ $transaction->validation_status === 'VALID' ? 'success' : ($transaction->validation_status === 'INVALID' ? 'danger' : 'info') }}">
                      {{ $transaction->validation_status }}
                    </span>
                  </td>
                </tr>
              </table>
            </div>
            <div class="col-md-6">
              <h6>Financial Details</h6>
              <table class="table table-sm">
                <tr>
                  <th width="150">Gross Sales</th>
                  <td>{{ number_format($transaction->gross_sales, 2) }}</td>
                </tr>
                <tr>
                  <th>Net Sales</th>
                  <td>{{ number_format($transaction->net_sales, 2) }}</td>
                </tr>
                <tr>
                  <th>Vatable Sales</th>
                  <td>{{ number_format($transaction->vatable_sales, 2) }}</td>
                </tr>
                <tr>
                  <th>VAT Amount</th>
                  <td>{{ number_format($transaction->vat_amount, 2) }}</td>
                </tr>
                <tr>
                  <th>Transaction Count</th>
                  <td>{{ $transaction->transaction_count }}</td>
                </tr>
              </table>
            </div>
          </div>

          @if($transaction->last_error)
          <div class="alert alert-danger mt-3">
            <h6>Error Information:</h6>
            <p>{{ $transaction->last_error }}</p>
          </div>
          @endif

          <div class="mt-3">
            <h6>Processing History</h6>
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Attempts</th>
                  <th>Created</th>
                  <th>Updated</th>
                  <th>Completed</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>{{ $transaction->job_attempts }}</td>
                  <td>{{ $transaction->created_at }}</td>
                  <td>{{ $transaction->updated_at }}</td>
                  <td>{{ $transaction->completed_at ?? 'Not completed' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer">
          @if($transaction->job_status === 'FAILED')
          <form action="{{ route('transactions.retry', $transaction->id) }}" method="POST" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-warning">Retry Transaction</button>
          </form>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection