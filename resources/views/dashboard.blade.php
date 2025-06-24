@extends('layouts.master')

@section('title', 'Dashboard')

@section('content')

  <!-- Summary Cards -->
  <div class="row mb-4">
    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="far fa-bookmark"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Total Transactions (7d)</span>
          <span class="info-box-number">{{ $metrics['total_transactions'] ?? 0 }}</span>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fa fa-desktop"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Total Terminals</span>
          <span class="info-box-number">{{ $terminalCount ?? 0 }}</span>
        </div>
      </div>
    </div>

     <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-money-bill"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Transactions</span>
          <span class="info-box-number">{{ $recentTransactionCount }}</span>
        </div>
      </div>
    </div>

   
    
   

    <div class="col-md-3 col-sm-6 col-12">
      <div class="info-box">
        <span class="info-box-icon bg-danger">
          <i class="fas fa-sad-tear"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Errors</span>
          <span class="info-box-number">{{ $errorCount }}</span>
        </div>
      </div>
    </div>

  </div> 

  <!-- POS Providers Section -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">POS Providers</h5>
    </div>
    <div class="card-body">
      <div class="row">
        @foreach($providers as $provider)
        <div class="col-md-4 mb-4">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">{{ $provider->name }}</h5>
              <p class="text-muted small mb-2">{{ $provider->code }}</p>
              <div class="row">
                <div class="col-6">
                  <div class="text-muted small">Total Terminals</div>
                  <div class="font-weight-bold">{{ $provider->total_terminals }}</div>
                </div>
                <div class="col-6">
                  <div class="text-muted small">Active Terminals</div>
                  <div class="font-weight-bold">{{ $provider->active_terminals }}</div>
                </div>
              </div>
              <div class="mt-2">
                <div class="text-muted small">Growth Rate (30d)</div>
                <div class="font-weight-bold">{{ $provider->growth_rate }}%</div>
              </div>
              <div class="mt-3">
                <a href="{{ route('dashboard.providers.show', $provider->id) }}" class="btn btn-sm btn-primary">View
                  Details</a>
              </div>
            </div>
          </div>
        </div>
        @endforeach
      </div>
    </div>
  </div>

  <!-- Transaction Metrics -->
  {{-- @include('transactions.partials.dashboard-metrics') --}}

  <!-- Terminal Enrollment History Chart -->
  {{-- <div class="card mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0">Terminal Enrollment History</h5>
    </div>
    <div class="card-body">
      <canvas id="terminalEnrollmentChart" height="300"></canvas>
    </div>
  </div> --}}

  <!-- Recent Terminal Enrollments -->
  {{-- <div class="card">
    <div class="card-header">
      <h5 class="card-title mb-0">Recent Terminal Enrollments</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>Terminal ID</th>
              <th>Provider</th>
              <th>Tenant</th>
              <th>Enrolled At</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            @foreach($recentTerminals as $terminal)
            <tr>
              <td>{{ $terminal->terminal_uid }}</td>
              <td>{{ $terminal->provider->name ?? 'Unknown' }}</td>
              <td>{{ $terminal->tenant->name ?? 'Unknown' }}</td>
              <td>{{ $terminal->enrolled_at->format('Y-m-d H:i') }}</td>
              <td>
                <span class="badge {{ $terminal->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                  {{ ucfirst($terminal->status) }}
                </span>
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div> --}}

  <!-- Recent Transactions -->
  <div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Recent Transactions</h5>
      <!-- <a href="{{ route('transactions.index') }}" class="btn btn-primary btn-sm">
        <i class="fas fa-list"></i> View All
      </a> -->
    </div>
    <div class="card-body">
      @include('transactions.partials.transaction-table', ['transactions' => $recentTransactions])
    </div>
  </div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('terminalEnrollmentChart').getContext('2d');
  const data = @json($enrollmentData ?? []);

  if (ctx) {
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.labels ?? [],
        datasets: [{
          label: 'Total Terminals',
          data: data.totalTerminals ?? [],
          borderColor: 'rgb(59, 130, 246)',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          fill: true
        }, {
          label: 'Active Terminals',
          data: data.activeTerminals ?? [],
          borderColor: 'rgb(16, 185, 129)',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          fill: true
        }, {
          label: 'New Enrollments',
          data: data.newEnrollments ?? [],
          type: 'bar',
          backgroundColor: 'rgba(245, 158, 11, 0.5)',
          borderColor: 'rgb(245, 158, 11)'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top'
          }
        },
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }
});
</script>
@endpush