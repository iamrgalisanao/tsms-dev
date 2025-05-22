@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2>Transaction Log Details</h2>
                <a href="{{ route('transactions.logs.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Logs
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Transaction Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="200">Transaction ID</th>
                                    <td>{{ $log->transaction_id }}</td>
                                </tr>
                                <tr>
                                    <th>Terminal</th>
                                    <td>{{ $log->terminal->identifier ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Amount</th>
                                    <td>₱{{ number_format($log->gross_sales, 2) }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="200">Status</th>
                                    <td>
                                        <span class="badge bg-{{ $log->validation_status === 'VALID' ? 'success' : ($log->validation_status === 'ERROR' ? 'danger' : 'warning') }}">
                                            {{ $log->validation_status }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created At</th>
                                    <td>{{ $log->created_at->format('M d, Y h:i A') }}</td>
                                </tr>
                                <tr>
                                    <th>Attempts</th>
                                    <td>{{ $log->job_attempts }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            @if($log->processingHistory->isNotEmpty())
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Processing History</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Message</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($log->processingHistory as $history)
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ $history->status_color }}">
                                            {{ $history->status }}
                                        </span>
                                    </td>
                                    <td>{{ $history->message }}</td>
                                    <td>{{ $history->created_at->format('M d, Y h:i:s A') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
