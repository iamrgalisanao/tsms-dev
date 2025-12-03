
@extends('layouts.master')

@section('title', 'Archived System Logs')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="card-title mb-0">Archived System Logs</h3>
                        <small class="text-muted">Soft-deleted logs. Restore or permanently purge selected items. Admin only.</small>
                    </div>
                    <div>
                        <a href="{{ route('system-logs.index') }}" class="btn btn-sm btn-secondary">Back to Logs</a>
                    </div>
                </div>
                <div class="card-body">
                    @if(session('status'))
                        <div class="alert alert-success">{{ session('status') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <form id="bulkActionsForm" method="POST" action="{{ route('system-logs.bulk-restore') }}">
                        @csrf
                        <div class="mb-2 d-flex flex-wrap align-items-center">
                            <button type="button" id="restoreSelected" class="btn btn-sm btn-primary mr-2">Restore Selected</button>
                            <button type="button" id="purgeSelected" class="btn btn-sm btn-danger mr-2">Permanently Purge Selected</button>
                        </div>

                        <div class="table-responsive">
                        <table class="table table-striped table-bordered" id="archivedLogsTable">
                            <thead>
                                <tr>
                                    <th style="width:1%"><input type="checkbox" id="select-all"></th>
                                    <th>ID</th>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Message</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($archivedLogs as $log)
                                <tr>
                                    <td><input type="checkbox" class="row-checkbox" value="{{ $log->id }}" aria-label="Select archived log {{ $log->id }}"></td>
                                    <td>{{ $log->id }}</td>
                                    <td>{{ $log->deleted_at?->format('Y-m-d H:i:s') ?? $log->created_at->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $log->user?->name ?? 'System' }}</td>
                                    <td>{{ $log->type }} / {{ $log->log_type }}</td>
                                    <td style="max-width: 400px;">{{ \Illuminate\Support\Str::limit($log->message, 200) }}</td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center">
                                            <form method="POST" action="{{ route('system-logs.bulk-restore') }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="ids[]" value="{{ $log->id }}">
                                                <button class="btn btn-sm btn-outline-primary mr-1">Restore</button>
                                            </form>

                                            <form method="POST" action="{{ route('system-logs.hard-delete', $log->id) }}" style="display:inline" onsubmit="return confirm('Permanently delete this log? This action cannot be undone.');">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger mr-1">Purge</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    </form>

                    <div class="mt-3">
                        {{ $archivedLogs->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function(){
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.row-checkbox');
    selectAll?.addEventListener('change', function(){
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
    });

    function getSelectedIds(){
        return Array.from(document.querySelectorAll('.row-checkbox:checked')).map(i => i.value);
    }

    document.getElementById('restoreSelected').addEventListener('click', function(){
        const ids = getSelectedIds();
        if(ids.length === 0){ alert('Select at least one row'); return; }
        const form = document.getElementById('bulkActionsForm');
        form.action = '{{ route('system-logs.bulk-restore') }}';
        // remove existing hidden inputs
        document.querySelectorAll('input[name="ids[]"]').forEach(n => n.remove());
        ids.forEach(id => {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'ids[]'; input.value = id; form.appendChild(input);
        });
        form.submit();
    });

    document.getElementById('purgeSelected').addEventListener('click', function(){
        const ids = getSelectedIds();
        if(ids.length === 0){ alert('Select at least one row'); return; }
        if(!confirm('Permanently purge the selected logs? This cannot be undone.')) return;
        const form = document.getElementById('bulkActionsForm');
        form.action = '{{ route('system-logs.bulk-purge') }}';
        document.querySelectorAll('input[name="ids[]"]').forEach(n => n.remove());
        ids.forEach(id => {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'ids[]'; input.value = id; form.appendChild(input);
        });
        form.submit();
    });
});
</script>
@endpush
