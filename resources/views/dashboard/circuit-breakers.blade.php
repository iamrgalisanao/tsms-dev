@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-header">
        <h5>Circuit Breakers</h5>
    </div>
    <div class="card-body">
        @if(count($circuitBreakers) > 0)
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Failures</th>
                            <th>Last Failure</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($circuitBreakers as $breaker)
                        <tr>
                            <td>{{ $breaker->service ?? 'Unknown' }}</td>
                            <td>
                                @if(($breaker->state ?? '') === 'open')
                                    <span class="badge bg-danger">Open</span>
                                @elseif(($breaker->state ?? '') === 'half-open')
                                    <span class="badge bg-warning">Half-Open</span>
                                @else
                                    <span class="badge bg-success">Closed</span>
                                @endif
                            </td>
                            <td>{{ $breaker->failure_count ?? 0 }}</td>
                            <td>{{ $breaker->last_failure_time ?? 'N/A' }}</td>
                            <td>
                                <form method="POST" action="/api/web/dashboard/circuit-breakers/{{ $breaker->id ?? 0 }}/reset">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Reset</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p>No circuit breakers found.</p>
        @endif
    </div>
</div>
@endsection
