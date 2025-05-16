@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-header">
        <h5>Transactions</h5>
    </div>
    <div class="card-body">
        @if(count($transactions) > 0)
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Terminal</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $transaction)
                        <tr>
                            <td>{{ $transaction->id }}</td>
                            <td>{{ $transaction->terminal_id ?? 'Unknown' }}</td>
                            <td>{{ $transaction->amount ?? 'N/A' }}</td>
                            <td>{{ $transaction->status ?? 'Unknown' }}</td>
                            <td>{{ $transaction->created_at }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p>No transactions found.</p>
        @endif
    </div>
</div>
@endsection
