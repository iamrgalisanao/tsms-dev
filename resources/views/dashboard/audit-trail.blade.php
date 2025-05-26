@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5>Audit Trail</h5>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" onclick="exportAuditTrail('csv')">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
            </div>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th class="text-center">Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $log->user->name ?? 'System' }}</td>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->message }}</td>
                            <td class="text-center">
                                @if($log->context)
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="showContext('{{ json_encode($log->context) }}')">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center">No audit logs found</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function showContext(context) {
    const modal = new bootstrap.Modal(document.getElementById('contextModal'));
    document.getElementById('contextContent').textContent = JSON.stringify(JSON.parse(context), null, 2);
    modal.show();
}

function exportAuditTrail(format) {
    window.location.href = `{{ route('audit-trail.export') }}?format=${format}`;
}
</script>
@endpush
@endsection
