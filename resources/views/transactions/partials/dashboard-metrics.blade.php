<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Today's Transactions</h6>
                <h2 class="card-title mb-0">{{ $metrics['today_count'] }}</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Success Rate</h6>
                <h2 class="card-title mb-0">{{ $metrics['success_rate'] }}%</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Processing Time</h6>
                <h2 class="card-title mb-0">{{ $metrics['avg_processing_time'] }}s</h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Error Rate</h6>
                <h2 class="card-title mb-0">{{ $metrics['error_rate'] }}%</h2>
            </div>
        </div>
    </div>
</div>
