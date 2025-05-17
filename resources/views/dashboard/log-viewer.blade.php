@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header">
    <h5>Audit & Admin Log Viewer</h5>
  </div>
  <div class="card-body">
    <!-- Log Statistics -->
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h6 class="m-0">Log Statistics</h6>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-2 text-center">
            <h3 id="total-logs">--</h3>
            <p class="text-muted">Total Logs</p>
          </div>
          <div class="col-md-2 text-center">
            <h3 id="log-errors">--</h3>
            <p class="text-muted">Errors</p>
          </div>
          <div class="col-md-2 text-center">
            <h3 id="log-warnings">--</h3>
            <p class="text-muted">Warnings</p>
          </div>
          <div class="col-md-2 text-center">
            <h3 id="log-info">--</h3>
            <p class="text-muted">Info</p>
          </div>
          <div class="col-md-2 text-center">
            <h3 id="latest-error">--</h3>
            <p class="text-muted">Latest Error</p>
          </div>
          <div class="col-md-2 text-center">
            <h3 id="logs-today">--</h3>
            <p class="text-muted">Logs Today</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between">
        <h6 class="m-0">Filters</h6>
        <div>
          <button id="btn-advanced-search" class="btn btn-sm btn-outline-primary me-2">Advanced Search</button>
          <div class="dropdown d-inline-block">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="exportDropdown"
              data-bs-toggle="dropdown" aria-expanded="false">
              Export
            </button>
            <ul class="dropdown-menu" aria-labelledby="exportDropdown">
              <li><a class="dropdown-item" href="#" id="export-csv">CSV</a></li>
              <li><a class="dropdown-item" href="#" id="export-pdf">PDF</a></li>
            </ul>
          </div>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label for="log-type" class="form-label">Log Type</label>
            <select id="log-type" class="form-select">
              <option value="all">All Logs</option>
              <option value="transaction">Transaction Logs</option>
              <option value="auth">Authentication Logs</option>
              <option value="error">Error Logs</option>
              <option value="security">Security Logs</option>
            </select>
          </div>

          <div class="col-md-3">
            <label for="log-terminal" class="form-label">POS Terminal</label>
            <select id="log-terminal" class="form-select">
              <option value="">All Terminals</option>
              @foreach($terminals as $terminal)
              <option value="{{ $terminal->id }}">{{ $terminal->terminal_uid }}</option>
              @endforeach
            </select>
          </div>

          <div class="col-md-3">
            <label for="log-severity" class="form-label">Severity</label>
            <select id="log-severity" class="form-select">
              <option value="all">All Severities</option>
              <option value="error">Error</option>
              <option value="warning">Warning</option>
              <option value="info">Info</option>
              <option value="debug">Debug</option>
            </select>
          </div>

          <div class="col-md-3">
            <label for="log-search" class="form-label">Search</label>
            <input type="text" id="log-search" class="form-control" placeholder="Search in log messages...">
          </div>

          <div class="col-md-3">
            <label for="log-date-from" class="form-label">Date From</label>
            <input type="date" id="log-date-from" class="form-control">
          </div>

          <div class="col-md-3">
            <label for="log-date-to" class="form-label">Date To</label>
            <input type="date" id="log-date-to" class="form-control">
          </div>

          <div class="col-md-6 align-self-end">
            <button id="btn-apply-filters" class="btn btn-primary me-2">Apply Filters</button>
            <button id="btn-reset-filters" class="btn btn-secondary">Reset</button>
            <div class="form-check form-switch d-inline-block ms-4">
              <input class="form-check-input" type="checkbox" id="live-updates">
              <label class="form-check-label" for="live-updates">Live Updates</label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="table-responsive">
      <table class="table table-striped" id="logs-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Type</th>
            <th>Severity</th>
            <th>Terminal</th>
            <th>Message</th>
            <th>Transaction</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colspan="7" class="text-center">Loading logs...</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-4">
      <div>
        <select id="logs-per-page" class="form-select form-select-sm">
          <option value="10">10 per page</option>
          <option value="25">25 per page</option>
          <option value="50">50 per page</option>
          <option value="100">100 per page</option>
        </select>
      </div>
      <div>
        <nav aria-label="Log pagination">
          <ul class="pagination" id="logs-pagination">
            <!-- Pagination will be added by JS -->
          </ul>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- Log Detail Modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1" aria-labelledby="logDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="logDetailModalLabel">Log Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold">Time:</label>
          <p id="modal-time"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Type:</label>
          <p id="modal-type"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Severity:</label>
          <p id="modal-severity"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Terminal:</label>
          <p id="modal-terminal"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Message:</label>
          <p id="modal-message"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Context:</label>
          <pre id="modal-context" class="bg-light p-3 rounded"></pre>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Transaction ID:</label>
          <p id="modal-transaction"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">User:</label>
          <p id="modal-user"></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  let currentPage = 1;
  let perPage = 10;
  let liveUpdatesEnabled = false;
  let liveUpdateInterval = null;
  let filters = {};

  // Elements
  const logsTable = document.getElementById('logs-table');
  const logsPerPage = document.getElementById('logs-per-page');
  const logsPagination = document.getElementById('logs-pagination');
  const liveUpdatesToggle = document.getElementById('live-updates');
  const btnApplyFilters = document.getElementById('btn-apply-filters');
  const btnResetFilters = document.getElementById('btn-reset-filters');
  const exportCsv = document.getElementById('export-csv');
  const exportPdf = document.getElementById('export-pdf');

  // Initialize
  fetchLogs();

  // Event listeners
  if (logsPerPage) {
    logsPerPage.addEventListener('change', function() {
      perPage = parseInt(this.value);
      currentPage = 1;
      fetchLogs();
    });
  }

  if (liveUpdatesToggle) {
    liveUpdatesToggle.addEventListener('change', function() {
      liveUpdatesEnabled = this.checked;
      if (liveUpdatesEnabled) {
        // Start live updates
        liveUpdateInterval = setInterval(fetchLogs, 5000);
      } else {
        // Stop live updates
        clearInterval(liveUpdateInterval);
      }
    });
  }

  if (btnApplyFilters) {
    btnApplyFilters.addEventListener('click', function() {
      collectFilters();
      currentPage = 1;
      fetchLogs();
    });
  }

  if (btnResetFilters) {
    btnResetFilters.addEventListener('click', function() {
      // Reset all form inputs
      document.getElementById('log-type').value = 'all';
      document.getElementById('log-terminal').value = '';
      document.getElementById('log-severity').value = 'all';
      document.getElementById('log-search').value = '';
      document.getElementById('log-date-from').value = '';
      document.getElementById('log-date-to').value = '';

      // Reset filters and fetch logs
      filters = {};
      currentPage = 1;
      fetchLogs();
    });
  }

  if (exportCsv) {
    exportCsv.addEventListener('click', function(e) {
      e.preventDefault();
      exportLogs('csv');
    });
  }

  if (exportPdf) {
    exportPdf.addEventListener('click', function(e) {
      e.preventDefault();
      exportLogs('pdf');
    });
  }

  // Collect filters from form inputs
  function collectFilters() {
    filters = {
      log_type: document.getElementById('log-type').value !== 'all' ? document.getElementById('log-type').value :
        '',
      terminal_id: document.getElementById('log-terminal').value || '',
      severity: document.getElementById('log-severity').value !== 'all' ? document.getElementById('log-severity')
        .value : '',
      search: document.getElementById('log-search').value || '',
      date_from: document.getElementById('log-date-from').value || '',
      date_to: document.getElementById('log-date-to').value || ''
    };
  }

  // Fetch logs from API
  function fetchLogs() {
    const tableBody = document.querySelector('#logs-table tbody');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="7" class="text-center">Loading logs...</td></tr>';

    // Build query parameters
    const params = new URLSearchParams();

    // Add page and per_page
    params.append('page', currentPage);
    params.append('per_page', perPage);

    // Add filters
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params.append(key, value);
    });

    // Fetch data from API
    fetch(`/api/web/dashboard/logs?${params.toString()}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        // Update statistics
        updateStats(data.stats);

        // Check if we have logs
        if (!data.logs || !data.logs.data || data.logs.data.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No logs found</td></tr>';
          if (logsPagination) logsPagination.innerHTML = '';
          return;
        }

        // Populate table
        let html = '';
        data.logs.data.forEach(log => {
          html += `
            <tr>
              <td>${formatDate(log.created_at)}</td>
              <td>${log.log_type || 'N/A'}</td>
              <td><span class="badge ${getSeverityClass(log.severity)}">${log.severity?.toUpperCase() || 'UNKNOWN'}</span></td>
              <td>${log.posTerminal ? log.posTerminal.terminal_uid : 'N/A'}</td>
              <td class="text-truncate" style="max-width: 300px;">${log.message || 'N/A'}</td>
              <td>${log.transaction_id || 'N/A'}</td>
              <td>
                <button class="btn btn-sm btn-info view-log-btn" data-id="${log.id}" 
                  data-log='${JSON.stringify(log)}'>View</button>
              </td>
            </tr>
          `;
        });

        tableBody.innerHTML = html;

        // Update pagination
        if (data.logs.meta) {
          renderPagination(data.logs.meta);
        }

        // Add event listeners to view buttons
        addViewButtonListeners();
      })
      .catch(error => {
        console.error('Error fetching logs:', error);
        tableBody.innerHTML =
          '<tr><td colspan="7" class="text-center text-danger">Error loading logs. Please try again later.</td></tr>';
      });
  }

  // Update statistics
  function updateStats(stats) {
    if (!stats) return;

    document.getElementById('total-logs').textContent = stats.total || '0';
    document.getElementById('log-errors').textContent = stats.errors || '0';
    document.getElementById('log-warnings').textContent = stats.warnings || '0';
    document.getElementById('log-info').textContent = stats.info || '0';
    document.getElementById('latest-error').textContent = stats.latest_error ? formatDate(stats.latest_error) :
      'None';
    document.getElementById('logs-today').textContent = stats.today || '0';
  }

  // Render pagination
  function renderPagination(meta) {
    if (!logsPagination) return;

    let html = '';

    // Previous button
    html += `
      <li class="page-item ${meta.current_page === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" data-page="${meta.current_page - 1}">&laquo;</a>
      </li>
    `;

    // Page numbers
    const totalPages = meta.last_page || 1;

    for (let i = 1; i <= totalPages; i++) {
      if (
        i === 1 ||
        i === totalPages ||
        (i >= meta.current_page - 2 && i <= meta.current_page + 2)
      ) {
        html += `
          <li class="page-item ${i === meta.current_page ? 'active' : ''}">
            <a class="page-link" href="#" data-page="${i}">${i}</a>
          </li>
        `;
      } else if (i === meta.current_page - 3 || i === meta.current_page + 3) {
        html += '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
      }
    }

    // Next button
    html += `
      <li class="page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}">
        <a class="page-link" href="#" data-page="${meta.current_page + 1}">&raquo;</a>
      </li>
    `;

    logsPagination.innerHTML = html;

    // Add click event listeners to pagination links
    document.querySelectorAll('#logs-pagination .page-link').forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        const page = parseInt(this.getAttribute('data-page'));
        if (!isNaN(page)) {
          currentPage = page;
          fetchLogs();
        }
      });
    });
  }

  // Add event listeners to view buttons
  function addViewButtonListeners() {
    document.querySelectorAll('.view-log-btn').forEach(button => {
      button.addEventListener('click', function() {
        const logData = JSON.parse(this.getAttribute('data-log'));
        showLogDetail(logData);
      });
    });
  }

  // Show log detail modal
  function showLogDetail(log) {
    document.getElementById('modal-time').textContent = formatDate(log.created_at);
    document.getElementById('modal-type').textContent = log.log_type || 'N/A';
    document.getElementById('modal-severity').textContent = log.severity ? log.severity.toUpperCase() : 'UNKNOWN';
    document.getElementById('modal-terminal').textContent = log.posTerminal ? log.posTerminal.terminal_uid : 'N/A';
    document.getElementById('modal-message').textContent = log.message || 'N/A';
    document.getElementById('modal-transaction').textContent = log.transaction_id || 'N/A';

    if (log.context) {
      try {
        const contextObj = typeof log.context === 'string' ? JSON.parse(log.context) : log.context;
        document.getElementById('modal-context').textContent = JSON.stringify(contextObj, null, 2);
      } catch (e) {
        document.getElementById('modal-context').textContent = log.context;
      }
    } else {
      document.getElementById('modal-context').textContent = 'No context data';
    }

    document.getElementById('modal-user').textContent = log.user ?
      `${log.user.name} (${log.user.email})` : 'N/A';

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('logDetailModal'));
    modal.show();
  }

  // Export logs
  function exportLogs(format) {
    // Collect current filters
    collectFilters();

    // Build query parameters
    const params = new URLSearchParams();
    params.append('format', format);

    // Add filters
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params.append(key, value);
    });

    // Redirect to export URL
    window.location.href = `/api/web/dashboard/logs/export?${params.toString()}`;
  }

  // Format date helper
  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'Invalid Date';
    return date.toLocaleString();
  }

  // Get severity class
  function getSeverityClass(severity) {
    if (!severity) return 'bg-secondary';

    switch (severity.toLowerCase()) {
      case 'error':
        return 'bg-danger';
      case 'warning':
        return 'bg-warning';
      case 'info':
        return 'bg-info';
      case 'debug':
        return 'bg-secondary';
      default:
        return 'bg-secondary';
    }
  }
});
</script>
@endsection