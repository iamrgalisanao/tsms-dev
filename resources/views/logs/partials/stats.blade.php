<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6>System Events</h6>
                <h3 class="mb-0">{{ number_format($stats['system'] ?? 0) }}</h3>
                <small>Last 24 Hours</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <h6>Failed Events</h6>
                <h3 class="mb-0">{{ number_format($stats['errors'] ?? 0) }}</h3>
                <small>Last 24 Hours</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning">
            <div class="card-body">
                <h6>Retry Events</h6>
                <h3 class="mb-0">{{ number_format($stats['retries'] ?? 0) }}</h3>
                <small>Last 24 Hours</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6>Completed</h6>
                <h3 class="mb-0">{{ number_format($stats['completed'] ?? 0) }}</h3>
                <small>Last Hour</small>
            </div>
        </div>
    </div>
</div>
