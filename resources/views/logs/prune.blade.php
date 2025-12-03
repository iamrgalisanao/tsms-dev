@extends('layouts.app')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h4>Prune System Logs</h4>
        </div>
        <div class="card-body">
            @if(session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <form method="POST" action="{{ route('system-logs.prune.exec') }}">
                @csrf
                <div class="mb-3">
                    <label for="before" class="form-label">Delete logs before (date)</label>
                    <input type="date" id="before" name="before" class="form-control" />
                    <div class="form-text">Specify a date to delete logs strictly earlier than this date.</div>
                </div>
                <div class="mb-3">
                    <label for="days" class="form-label">OR delete logs older than (days)</label>
                    <input type="number" id="days" name="days" class="form-control" min="1" />
                    <div class="form-text">Provide number of days (e.g., 90) to delete logs older than N days.</div>
                </div>
                <div class="mb-3">
                    <label for="type" class="form-label">Optional: Log type</label>
                    <select id="type" name="type" class="form-control">
                        <option value="">All types</option>
                        <option value="audit">Audit</option>
                        <option value="system">System</option>
                        <option value="webhook">Webhook</option>
                        <option value="transaction">Transaction</option>
                        <option value="retry">Retry</option>
                        <option value="terminal_heartbeat">Terminal Heartbeat</option>
                    </select>
                    <div class="form-text">Optional: restrict prune to a specific log type.</div>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="dry_run" name="dry_run" checked>
                    <label class="form-check-label" for="dry_run">Dry run (default)</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1" id="force" name="force">
                    <label class="form-check-label" for="force">Force (actually delete). Use with caution.</label>
                </div>

                <button type="submit" class="btn btn-danger">Run Prune</button>
                <a href="{{ route('system-logs.index') }}" class="btn btn-secondary">Back to logs</a>
            </form>
        </div>
    </div>
</div>
@endsection
