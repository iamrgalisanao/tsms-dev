@extends('layouts.master')

@section('title', 'Dashboard')

@section('content')

  <!-- Dynamic Dashboard Metrics -->
  <div class="row mb-4" id="dashboard-metrics">
    <!-- Metrics will be loaded here by JS -->
  </div>

  </div> 

  <!-- POS Providers Section -->
  {{-- <div class="card mb-4">
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
  </div> --}}

  <!-- Transaction Metrics Chart -->
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0">Transaction Metrics (Last 7 Days)</h5>
    </div>
    <div class="card-body">
      <canvas id="dashboardChart" height="120"></canvas>
    </div>
  </div>

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

  <!-- Recent Transactions (AJAX) -->
  <div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Recent Transactions</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped" id="transactions-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Terminal</th>
              <th>Tenant</th>
              <th>Amount</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <!-- Transactions will be loaded here by JS -->
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- Audit Logs (AJAX, RBAC protected) -->
  <div class="card mt-4">
    <div class="card-header">
      <h5 class="mb-0">Audit Logs</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped" id="audit-logs-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Action</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <!-- Audit logs will be loaded here by JS -->
          </tbody>
        </table>
      </div>
    </div>
  </div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Load dashboard metrics
  fetch('/api/dashboard/metrics')
    .then(res => res.json())
    .then(data => {
      const metricsHtml = `
        <div class="col-md-3 col-sm-6 col-12">
          <div class="info-box">
            <span class="info-box-icon bg-danger"><i class="far fa-bookmark"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Sales Today</span>
              <span class="info-box-number">${data.total_sales ?? 0}</span>
            </div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6 col-12">
          <div class="info-box">
            <span class="info-box-icon bg-danger"><i class="fa fa-desktop"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Transactions</span>
              <span class="info-box-number">${data.total_transactions ?? 0}</span>
            </div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6 col-12">
          <div class="info-box">
            <span class="info-box-icon bg-danger"><i class="fas fa-money-bill"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Voided Transactions</span>
              <span class="info-box-number">${data.voided_transactions ?? 0}</span>
            </div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6 col-12">
          <div class="info-box">
            <span class="info-box-icon bg-danger"><i class="fas fa-sad-tear"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Active Terminals</span>
              <span class="info-box-number">${data.active_terminals ?? 0}</span>
            </div>
          </div>
        </div>
      `;
      document.getElementById('dashboard-metrics').innerHTML = metricsHtml;
    });

  // Load dashboard chart
  fetch('/api/dashboard/charts')
    .then(res => res.json())
    .then(data => {
      const ctx = document.getElementById('dashboardChart').getContext('2d');
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [
            {
              label: 'Sales',
              data: data.sales,
              borderColor: 'rgb(59, 130, 246)',
              backgroundColor: 'rgba(59, 130, 246, 0.1)',
              fill: true
            },
            {
              label: 'Volume',
              data: data.volume,
              borderColor: 'rgb(16, 185, 129)',
              backgroundColor: 'rgba(16, 185, 129, 0.1)',
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'top' } },
          scales: { y: { beginAtZero: true } }
        }
      });
    });

  // Load recent transactions
  fetch('/api/dashboard/transactions')
    .then(res => res.json())
    .then(transactions => {
      const tbody = document.querySelector('#transactions-table tbody');
      tbody.innerHTML = transactions.map(tx => `
        <tr>
          <td>${tx.id}</td>
          <td>${tx.terminal_id}</td>
          <td>${tx.tenant_id}</td>
          <td>${tx.gross_sales}</td>
          <td>${tx.transaction_timestamp}</td>
        </tr>
      `).join('');
    });

  // Load audit logs (RBAC protected)
  fetch('/api/dashboard/audit-logs')
    .then(res => res.json())
    .then(logs => {
      const tbody = document.querySelector('#audit-logs-table tbody');
      if (Array.isArray(logs)) {
        tbody.innerHTML = logs.map(log => `
          <tr>
            <td>${log.id}</td>
            <td>${log.user_id ?? ''}</td>
            <td>${log.action ?? ''}</td>
            <td>${log.created_at ?? ''}</td>
          </tr>
        `).join('');
      } else {
        tbody.innerHTML = `<tr><td colspan="4">${logs.error ?? 'No logs available'}</td></tr>`;
      }
    });
});
</script>
@endpush