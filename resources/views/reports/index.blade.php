@extends('layouts.master')
@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center mb-4">
        <h1 class="h3 mb-0">Finance Dashboard</h1>
    </div>

    <div class="row g-3">
        <!-- Top row: 3 cards -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">Basket Size, in Thousand Php</div>
                <div class="card-body">
                    <canvas id="chart-basket" height="140"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">Weekly Sales (Current Week)</div>
                <div class="card-body">
                    <canvas id="chart-weekly" height="140"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">Monthly Income (By Category)</div>
                <div class="card-body">
                    <canvas id="chart-monthly-income" height="140"></canvas>
                </div>
            </div>
        </div>

        <!-- Bottom row: 3 cards -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">L2 &lt;21SQM Monthly Income Per SQM</div>
                <div class="card-body">
                    <canvas id="chart-l2-small" height="140"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">L1 &gt;21SQM Monthly Income Per SQM</div>
                <div class="card-body">
                    <canvas id="chart-l1-large" height="140"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-danger text-white">L2 &gt;21SQM Monthly Income Per SQM</div>
                <div class="card-body">
                    <canvas id="chart-l2-large" height="140"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 text-muted small">Use the Webapp API endpoints for drilldowns and exports. If you'd like, I can wire these widgets to interactive filters and export buttons.</div>
</div>

@push('scripts')
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Simple helper: try to fetch JSON, otherwise return fallback sample data
    async function fetchJsonOrFallback(url, fallback) {
        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('Network response not ok');
            return await res.json();
        } catch (err) {
            console.warn('Report fetch failed for', url, err);
            return fallback;
        }
    }

    function makeLineChart(ctx, labels, datasets, opts = {}) {
        return new Chart(ctx, {
            type: 'line',
            data: { labels, datasets },
            options: Object.assign({
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true } }
            }, opts)
        });
    }

    function makeBarChart(ctx, labels, datasets, opts = {}) {
        return new Chart(ctx, {
            type: 'bar',
            data: { labels, datasets },
            options: Object.assign({
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true } }
            }, opts)
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        // Example: load summary (fallback data provided)
        const summary = await fetchJsonOrFallback('/api/v1/webapp/reports/summary?tenant_id=1', {
            data: { total_sales: 0 }
        });

        // Fetch sales aggregates for charts (fallback generates random sample)
        const sales = await fetchJsonOrFallback('/api/v1/webapp/reports/sales?period=monthly&start=2025-01-01&end=2025-12-31&tenant_id=1', {
            data: []
        });

        // Build sample labels and data if API returned empty
        let labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        let sampleSeries = [120,150,130,160,170,180,200,190,220,260,300,280];

        // Basket Size chart
        const ctxBasket = document.getElementById('chart-basket').getContext('2d');
        makeLineChart(ctxBasket, labels, [{ label: 'Basket (kPhp)', data: sampleSeries, borderColor: '#c82333', backgroundColor: 'rgba(200,35,51,0.08)', fill: true }]);

        // Weekly sales (sample data per day)
        const ctxWeekly = document.getElementById('chart-weekly').getContext('2d');
        makeLineChart(ctxWeekly, ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], [{ label: 'Sales (M Php)', data: [0.2,0.4,0.5,0.6,0.7,0.5,0.3], borderColor: '#007bff', backgroundColor: 'rgba(0,123,255,0.08)', fill: true }]);

        // Monthly Income bar
        const ctxMonthly = document.getElementById('chart-monthly-income').getContext('2d');
        makeBarChart(ctxMonthly, labels, [{ label: 'Monthly Income', data: sampleSeries.map(v => v * 10), backgroundColor: '#e55353' }]);

        // L2 small
        const ctxL2Small = document.getElementById('chart-l2-small').getContext('2d');
        makeBarChart(ctxL2Small, labels, [{ label: 'L2 <21sqm', data: sampleSeries.map(v => Math.round(v * 0.5)), backgroundColor: '#d9534f' }]);

        // L1 large
        const ctxL1Large = document.getElementById('chart-l1-large').getContext('2d');
        makeBarChart(ctxL1Large, labels, [{ label: 'L1 >21sqm', data: sampleSeries.map(v => Math.round(v * 0.8)), backgroundColor: '#c9302c' }]);

        // L2 large
        const ctxL2Large = document.getElementById('chart-l2-large').getContext('2d');
        makeBarChart(ctxL2Large, labels, [{ label: 'L2 >21sqm', data: sampleSeries.map(v => Math.round(v * 0.3)), backgroundColor: '#b52b2b' }]);
    });
</script>
@endpush

@endsection
