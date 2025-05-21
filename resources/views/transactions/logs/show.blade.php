@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex justify-content-between align-items-center">
                <h2>Transaction Log Details</h2>
                <a href="{{ route('transactions.logs.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Logs
                </a>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <!-- Log details section -->
                    @include('transactions.logs.partials.log-details', ['log' => $log])
                    
                    <!-- Processing history section -->
                    @include('transactions.logs.partials.processing-history', ['history' => $log->processingHistory])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
