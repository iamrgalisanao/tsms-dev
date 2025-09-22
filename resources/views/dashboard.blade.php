@extends('layouts.master')

@section('title', 'Dashboard')

@push('styles')
<!-- DataTables -->
<link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/toastr/toastr.min.css') }}">

@endpush

@section('content')
<div class="container-fluid">
  <!-- Dynamic Dashboard Metrics -->
  <div class="row mb-4" id="dashboard-metrics">
    <!-- Metrics will be loaded here by JS -->
  </div>

  {{-- Admin notifications for excessive failed transactions --}}
  @if(isset($adminNotifications) && count($adminNotifications) > 0)
    @foreach($adminNotifications as $notification)
      @php $data = json_decode($notification->data, true); @endphp
      <div class="alert alert-danger admin-notification" data-id="{{ $notification->id }}">
        <strong>Alert:</strong> POS Terminal <b>{{ $data['pos_terminal_id'] ?? 'N/A' }}</b> exceeded failure threshold.<br>
        Severity: <b>{{ $data['severity'] ?? 'N/A' }}</b><br>
        Count: <b>{{ $data['threshold_data']['current_count'] ?? 'N/A' }}</b><br>
        Time: <b>{{ isset($notification->created_at) ? (is_string($notification->created_at) ? $notification->created_at : $notification->created_at->format('Y-m-d H:i:s')) : 'N/A' }}</b>
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
              <td>{{ $terminal->tenant->trade_name ?? 'Unknown' }}</td>
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
  <div class="card">
    <div class="card-header bg-primary">
      <h5 class="card-title text-white">Recent Transactions</h5>
    </div>
    <div class="card-body">
      
        {{-- <table class="table table-striped" id="transactions-table"> --}}
        <table class="table table-striped" id="transactionsTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Transaction ID</th>
              <th>Tenant Code</th>
              <th>Terminal</th>
              <th>Tenant</th>
              <th>Net Sales</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            @foreach($recentTransactions as $tx)
            <tr>
              <td>{{ $tx->id }}</td>
              <td>{{ $tx->transaction_id }}</td>
              <td>{{ $tx->display_tenant_code }}</td>
              <td>{{ $tx->terminal_id }}</td>
              <td>{{ $tx->tenant->trade_name ?? 'Unknown' }}</td>
              <td>{{ number_format($tx->net_sales, 2, '.', ',') }}</td>
              <td>{{ $tx->transaction_timestamp }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      
    </div>
  </div>
  <!-- Audit Logs (AJAX, RBAC protected) -->
  <div class="card mt-4">
    <div class="card-header bg-primary">
      <h5 class="mb-0">Audit Logs</h5>
    </div>
    <div class="card-body">
        <table class="table table-striped" id="auditLogsTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Action</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            @foreach($auditLogs as $log)
            <tr>
              <td>{{ $log->id }}</td>
              <td>{{ $log->user->name ?? 'System' }}</td>
              <td>{{ $log->action }}</td>
              <td>{{ $log->created_at }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<!-- DataTables & Plugins -->
<script src="{{ asset('plugins/datatables/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-responsive/js/responsive.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/dataTables.buttons.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/jszip/jszip.min.js') }}"></script>
<script src="{{ asset('plugins/pdfmake/pdfmake.min.js') }}"></script>
<script src="{{ asset('plugins/pdfmake/vfs_fonts.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.html5.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.print.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.colVis.min.js') }}"></script>
<script src="{{ asset('plugins/toastr/toastr.min.js') }}"></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Load dashboard metrics
  fetch('/api/dashboard/metrics')
    .then(res => {
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      return res.json();
    })
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
    })
    .catch(error => {
      console.error('Error loading dashboard metrics:', error);
      document.getElementById('dashboard-metrics').innerHTML = `
        <div class="col-12">
          <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i>
            Error loading dashboard metrics: ${error.message}
          </div>
        </div>
      `;
    });

  // Load dashboard chart
  fetch('/api/dashboard/charts')
    .then(res => {
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      return res.json();
    })
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
    })
    .catch(error => {
      console.error('Error loading dashboard charts:', error);
      const chartContainer = document.getElementById('dashboardChart').parentElement;
      chartContainer.innerHTML = `
        <div class="alert alert-danger">
          <i class="fas fa-exclamation-triangle"></i>
          Error loading chart data: ${error.message}
        </div>
      `;
    });

  // Load recent transactions
  fetch('/api/dashboard/transactions')
    .then(res => {
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      return res.json();
    })
    .then(data => {
      // Handle both paginated and direct array responses
      const transactions = data.data || data;
      const tbody = document.querySelector('#transactionsTable tbody');
      if (tbody && Array.isArray(transactions)) {
        tbody.innerHTML = transactions.slice(0, 10).map(tx => `
          <tr>
            <td>${tx.id}</td>
            <td>${tx.transaction_id}</td>
            <td>${tx.display_tenant_code ?? tx.customer_code ?? ''}</td>
            <td>${tx.terminal_id}</td>
            <td>${tx.tenant?.trade_name || 'Unknown'}</td>
            <td>${tx.net_sales ? parseFloat(tx.net_sales).toFixed(2) : '0.00'}</td>
            <td>${tx.transaction_timestamp}</td>
          </tr>
        `).join('');
      }
    })
    .catch(error => {
      console.error('Error loading transactions:', error);
      const tbody = document.querySelector('#transactionsTable tbody');
      if (tbody) {
        tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center text-danger">
              <i class="fas fa-exclamation-triangle"></i>
              Error loading transactions: ${error.message}
            </td>
          </tr>
        `;
      }
    });

  // Load audit logs (RBAC protected) with DataTables pagination
  fetch('/api/dashboard/audit-logs')
    .then(res => {
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      return res.json();
    })
    .then(logs => {
      const table = document.getElementById('auditLogsTable');
      if (!table) return;
      const tbody = table.querySelector('tbody');
      if (!tbody) return;
      if (Array.isArray(logs)) {
        // Limit to 10 records for display
        const displayLogs = logs.slice(0, 10);
        tbody.innerHTML = displayLogs.map(log => `
          <tr>
            <td>${log.id}</td>
            <td>${log.user?.name || 'System'}</td>
            <td>${log.action ?? ''}</td>
            <td>${log.created_at ?? ''}</td>
          </tr>
        `).join('');
      } else {
        tbody.innerHTML = `<tr><td colspan="4">${logs.error ?? 'No logs available'}</td></tr>`;
      }
    })
    .catch(error => {
      console.error('Error loading audit logs:', error);
      const tbody = document.querySelector('#auditLogsTable tbody');
      if (tbody) {
        tbody.innerHTML = `
          <tr>
            <td colspan="4" class="text-center text-danger">
              <i class="fas fa-exclamation-triangle"></i>
              Error loading audit logs: ${error.message}
            </td>
          </tr>
        `;
      }
    });
});
</script>
<script>
$(function () {
    const selector = '#transactionsTable';
    if ($.fn.DataTable.isDataTable(selector)) {
        return;
    }
        $(selector).DataTable({
        "responsive": true, 
        "lengthChange": false, 
        "autoWidth": false,
        "ordering": true,
        "info": true,
        "paging": true,
        "searching": true,
        "pageLength": 10,
        "language": {
            "emptyTable": "No transactions available",
            "zeroRecords": "No matching records found",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "infoEmpty": "Showing 0 to 0 of 0 entries",
            "infoFiltered": "(filtered from _MAX_ total entries)",
            "search": "Search:",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        },
        "buttons": [
            { extend: "csv",   text: "CSV",   className: "btn btn-danger" },
              { extend: "excel", text: "Excel", className: "btn btn-danger" },
              { extend: "pdf",   text: "PDF",   className: "btn btn-danger" },
              // { extend: "print", text: "Print", className: "btn btn-sm btn-danger" },
              { extend: "colvis",text: "Cols",  className: "btn btn-lg btn-danger" }
        ]
    }).buttons().container().appendTo('#transactionsTable_wrapper .col-md-6:eq(0)');

    // Toastr notifications
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif

    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
});
</script>
<script>
$(function () {
    const selector = '#auditLogsTable';
    if ($.fn.DataTable.isDataTable(selector)) {
        return;
    }
    $(selector).DataTable({
        "responsive": true, 
        "lengthChange": false, 
        "autoWidth": false,
        "ordering": true,
        "info": true,
        "paging": true,
        "searching": true,
        "pageLength": 10,
        "language": {
            "emptyTable": "No audit logs available",
            "zeroRecords": "No matching records found",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "infoEmpty": "Showing 0 to 0 of 0 entries",
            "infoFiltered": "(filtered from _MAX_ total entries)",
            "search": "Search:",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        },
        "buttons": [
            { extend: "csv",   text: "CSV",   className: "btn btn-danger" },
              { extend: "excel", text: "Excel", className: "btn btn-danger" },
              { extend: "pdf",   text: "PDF",   className: "btn btn-danger" },
              // { extend: "print", text: "Print", className: "btn btn-sm btn-danger" },
              { extend: "colvis",text: "Cols",  className: "btn btn-lg btn-danger" }
        ]
    }).buttons().container().appendTo('#auditLogsTable_wrapper .col-md-6:eq(0)');

    // Toastr notifications
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif

    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
});
</script>
@endpush