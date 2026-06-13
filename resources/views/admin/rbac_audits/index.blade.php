@extends('layouts.app')

@section('content')
<div class="container">
    <h1>RBAC Audit Log</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Time</th>
                <th>Event</th>
                <th>Actor</th>
                <th>Target</th>
                <th>Meta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($audits as $a)
            <tr>
                <td>{{ $a->created_at }}</td>
                <td>{{ $a->event_type }}</td>
                <td>{{ $a->actor_id }}</td>
                <td>{{ $a->target_type }}: {{ $a->target_name }}</td>
                <td><pre style="margin:0">{{ json_encode($a->meta, JSON_PRETTY_PRINT) }}</pre></td>
            </tr>
            @endforeach
        </tbody>
    </table>
    {{ $audits->links() }}
</div>
@endsection
