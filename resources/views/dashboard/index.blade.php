@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-header">
        <h5>Dashboard</h5>
    </div>
    <div class="card-body">
        <p>Welcome to the TSMS Dashboard!</p>
        <p>You are now logged in as {{ Auth::user()->email }}.</p>
        <div class="alert alert-info">
            <p>Note: The dashboard is currently running in fallback mode without React components.</p>
            <p>To enable the full React dashboard, you'll need to:</p>
            <ol>
                <li>Make sure Node.js and npm are installed</li>
                <li>Run <code>npm install</code> to install dependencies</li>
                <li>Run <code>npm run dev</code> to compile front-end assets</li>
            </ol>
        </div>

        {{-- Admin notifications for excessive failed transactions --}}
        @if(isset($adminNotifications) && count($adminNotifications) > 0)
            @foreach($adminNotifications as $notification)
                @php $data = json_decode($notification->data, true); @endphp
                <div class="alert alert-danger admin-notification" data-id="{{ $notification->id }}">
                    <strong>Alert:</strong> POS Terminal <b>{{ $data['pos_terminal_id'] ?? 'N/A' }}</b> exceeded failure threshold.<br>
                    Severity: <b>{{ $data['severity'] ?? 'N/A' }}</b><br>
                    Count: <b>{{ $data['threshold_data']['current_count'] ?? 'N/A' }}</b><br>
                    Time: <b>{{ $notification->created_at }}</b>
                    <button type="button" class="btn btn-sm btn-outline-light float-end dismiss-notification">Dismiss</button>
                </div>
            @endforeach
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.dismiss-notification').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        var parent = e.target.closest('.admin-notification');
                        var id = parent.getAttribute('data-id');
                        fetch("{{ route('dashboard.notifications.dismiss') }}", {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({id: id})
                        }).then(res => res.json()).then(data => {
                            if (data.success) {
                                parent.remove();
                            }
                        });
                    });
                });
            });
            </script>
        @endif
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Recent Activity</div>
            <div class="card-body">
                <p>No recent activity to display.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">System Status</div>
            <div class="card-body">
                <p>All systems operational.</p>
            </div>
        </div>
    </div>
</div>
@endsection
