@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="mb-4">
    <h2>Provider Details: {{ $provider->name }}</h2>
    <a href="{{ route('dashboard') }}" class="btn btn-secondary">&larr; Back to Dashboard</a>
  </div>

  <div class="row">
    <!-- Provider Info Card -->
    <div class="col-md-4">
      <div class="card mb-4">
        <div class="card-header">Provider Information</div>
        <div class="card-body">
          <p><strong>Code:</strong> {{ $provider->code }}</p>
          <p><strong>Status:</strong> {{ $provider->status }}</p>
          <p><strong>Contact Email:</strong> {{ $provider->contact_email }}</p>
          <p><strong>Contact Phone:</strong> {{ $provider->contact_phone }}</p>
        </div>
      </div>
    </div>

    <!-- Metrics Card -->
    <div class="col-md-8">
      <div class="card mb-4">
        <div class="card-header">Terminal Metrics</div>
        <div class="card-body">
          <div class="row">
            <div class="col-sm-3">
              <div class="metric-box">
                <h4>Total Terminals</h4>
                <p class="h2">{{ $metrics['total_terminals'] }}</p>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="metric-box">
                <h4>Active Terminals</h4>
                <p class="h2">{{ $metrics['active_terminals'] }}</p>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="metric-box">
                <h4>Success Rate</h4>
                <p class="h2">{{ $metrics['success_rate'] }}%</p>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="metric-box">
                <h4>24h Transactions</h4>
                <p class="h2">{{ $metrics['last_24h_transactions'] }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Add Chart Container -->
  <div class="row">
    <div class="col-md-12">
      <div class="card mb-4">
        <div class="card-header">Terminal Enrollment History</div>
        <div class="card-body">
          <canvas id="enrollmentChart" style="height: 300px;"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@section('styles')
<style>
.metric-box {
  text-align: center;
  padding: 15px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
}

.metric-box h4 {
  font-size: 14px;
  margin-bottom: 10px;
  color: #6c757d;
}
</style>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('enrollmentChart').getContext('2d');
  const data = @json($chartData);

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: data.labels,
      datasets: [{
          label: 'Total Terminals',
          data: data.terminalCount,
          borderColor: 'rgb(59, 130, 246)',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          fill: true,
          tension: 0.4
        },
        {
          label: 'Active Terminals',
          data: data.activeCount,
          borderColor: 'rgb(16, 185, 129)',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          fill: true,
          tension: 0.4
        },
        {
          label: 'New Enrollments',
          data: data.newEnrollments,
          borderColor: 'rgb(245, 158, 11)',
          backgroundColor: 'rgba(245, 158, 11, 0.5)',
          type: 'bar'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top'
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            drawBorder: false
          }
        },
        x: {
          grid: {
            display: false
          }
        }
      }
    }
  });
});
</script>
@endsection