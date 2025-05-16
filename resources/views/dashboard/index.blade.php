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
