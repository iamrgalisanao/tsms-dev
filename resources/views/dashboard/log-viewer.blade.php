@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <h5>Audit & Admin Log Viewer</h5>
    <div>
      @if(session('success'))
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      @endif
      @if(session('error'))
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      @endif
    </div>
  </div>

  <div class="card-body">
    <!-- Log Statistics Overview Panel -->
    <div class="row mb-4">
      <div class="col-md-12">
        <div class="card">
          <div class="card-header bg-light">
            <h6 class="mb-0">Log Statistics</h6>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-2 text-center">
                <h3 id="total-logs">--</h3>
                <p class="text-muted">Total Logs</p>
              </div>
              <div class="col-md-2 text-center">
                <h3 id="error-count">--</h3>
                <p class="text-muted">Errors</p>
              </div>
              <div class="col-md-2 text-center">
                <h3 id="warning-count">--</h3>
                <p class="text-muted">Warnings</p>
              </div>
              <div class="col-md-2 text-center">
                <h3 id="info-count">--</h3>
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
      </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
      <div class="col-md-12">
        <div class="card">
          <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
              <h6 class="mb-0">Filters</h6>
              <div class="d-flex">
                <button id="toggle-advanced-search" class="btn btn-sm btn-outline-primary me-2">
                  Advanced Search
                </button>
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="exportDropdown"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    Export
                  </button>
                  <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                    <li>
                      <a class="dropdown-item" href="#" id="export-csv">
                        Export to CSV
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="#" id="export-pdf">
                        Export to PDF
                      </a>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
          <div class="card-body">
            <form id="filter-form" class="row g-3">
              <div class="col-md-4">
                <label for="log_type" class="form-label">Log Type</label>
                <select class="form-select" id="log_type" name="log_type">
                  @foreach($logTypes as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-4">
                <label for="terminal_id" class="form-label">POS Terminal</label>
                <select class="form-select" id="terminal_id" name="terminal_id">
                  <option value="">All Terminals</option>
                  @foreach($terminals as $terminal)
                  <option value="{{ $terminal->id }}">{{ $terminal->terminal_uid }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-4">
                <label for="severity" class="form-label">Severity</label>
                <select class="form-select" id="severity" name="severity">
                  <option value="">All Severities</option>
                  <option value="error">Error</option>
                  <option value="warning">Warning</option>
                  <option value="info">Info</option>
                  <option value="debug">Debug</option>
                </select>
              </div>
              @if(isset($isAdmin) && $isAdmin)
              <div class="col-md-4">
                <label for="user_id" class="form-label">User</label>
                <select class="form-select" id="user_id" name="user_id">
                  <option value="">All Users</option>
                  @foreach($users as $user)
                  <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                  @endforeach
                </select>
              </div>
              @endif
              <div class="col-md-4">
                <label for="date_from" class="form-label">Date From</label>
                <input type="date" class="form-control" id="date_from" name="date_from">
              </div>
              <div class="col-md-4">
                <label for="date_to" class="form-label">Date To</label>
                <input type="date" class="form-control" id="date_to" name="date_to">
              </div>
              <div class="col-md-12">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search"
                  placeholder="Search in log messages, transaction IDs, etc.">
              </div>

              <!-- Advanced Search Panel (hidden by default) -->
              <div id="advanced-search-panel" class="col-12 mt-3 border-top pt-3" style="display: none;">
                <h6>Advanced Search</h6>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="search_field" class="form-label">Search Field</label>
                    <select class="form-select" id="search_field" name="search_field">
                      <option value="message">Message</option>
                      <option value="transaction_id">Transaction ID</option>
                      <option value="context">Context</option>
                      <option value="all">All Fields</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label for="search_operator" class="form-label">Operator</label>
                    <select class="form-select" id="search_operator" name="search_operator">
                      <option value="contains">Contains</option>
                      <option value="equals">Equals</option>
                      <option value="starts_with">Starts With</option>
                      <option value="ends_with">Ends With</option>
                      <option value="regex">Regex</option>
                    </select>
                  </div>
                  <div class="col-md-12">
                    <label for="search_value" class="form-label">Search Value</label>
                    <input type="text" class="form-control" id="search_value" name="search_value"
                      placeholder="Enter search value">
                  </div>
                </div>
              </div>

              <div class="col-12 mt-3">
                <div class="d-flex align-items-center">
                  <button type="submit" class="btn btn-primary">Apply Filters</button>
                  <button type="button" id="reset-filters" class="btn btn-outline-secondary ms-2">Reset</button>
                  <div class="form-check form-switch ms-3">
                    <input class="form-check-input" type="checkbox" id="live-updates" name="live_updates">
                    <label class="form-check-label" for="live-updates">Live Updates</label>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="table-responsive">
      <table class="table table-striped table-hover">
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
        <tbody id="logs-table">
          <tr>
            <td colspan="7" class="text-center">Loading logs...</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-4">
      <div>
        <select id="per-page" class="form-select form-select-sm">
          <option value="10">10 per page</option>
          <option value="25">25 per page</option>
          <option value="50">50 per page</option>
          <option value="100">100 per page</option>
        </select>
      </div>
      <nav aria-label="Logs pagination">
        <ul class="pagination" id="pagination">
          <!-- Pagination links will be generated here -->
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Export Form (hidden) -->
<form id="export-form" method="POST" action="{{ route('dashboard.log-viewer.export') }}" class="d-none">
  @csrf
  <input type="hidden" name="format" id="export-format" value="csv">
  <!-- Filter fields will be added dynamically via JavaScript -->
</form>

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
          <label class="form-label fw-bold">Message:</label>
          <p id="modal-message"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Context:</label>
          <pre id="modal-context" class="bg-light p-3 border rounded"></pre>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Transaction ID:</label>
          <p id="modal-transaction-id"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Terminal:</label>
          <p id="modal-terminal"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">User:</label>
          <p id="modal-user"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Tenant:</label>
          <p id="modal-tenant"></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
  let currentPage = 1;
  let perPage = 10;
  let activeFilters = {}; // Store active filters

  // Initialize Bootstrap Modal and other UI components
  let logDetailModal = null;
  const logDetailModalEl = document.getElementById('logDetailModal');
  if (logDetailModalEl && typeof bootstrap !== 'undefined') {
    logDetailModal = new bootstrap.Modal(logDetailModalEl);
  }

  // Initial load
  loadLogs();

  // Filter form submission
  document.getElementById('filter-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    activeFilters = {}; // Reset active filters

    // Process form data into activeFilters object
    for (const [key, value] of formData.entries()) {
      if (value) {
        activeFilters[key] = value;
      }
    }

    console.log('Active filters:', activeFilters);
    currentPage = 1; // Reset to first page when applying filters
    loadLogs();
  });

  // Reset filters
  document.getElementById('reset-filters').addEventListener('click', function() {
    document.getElementById('filter-form').reset();
    activeFilters = {}; // Clear active filters
    currentPage = 1;
    loadLogs();
  });

  // Per page change
  document.getElementById('per-page').addEventListener('change', function() {
    perPage = parseInt(this.value);
    currentPage = 1; // Reset to first page when changing items per page
    loadLogs();
  });

  // Export handlers
  document.getElementById('export-csv').addEventListener('click', function(e) {
    e.preventDefault();
    exportLogs('csv');
  });

  document.getElementById('export-pdf').addEventListener('click', function(e) {
    e.preventDefault();
    exportLogs('pdf');
  });

  // Event delegation for dynamic elements
  document.addEventListener('click', function(e) {
    // Handle view log detail clicks
    if (e.target.classList.contains('view-log-btn')) {
      const logData = JSON.parse(e.target.getAttribute('data-log'));
      showLogDetailModal(logData);
    }

    // Handle pagination clicks
    if (e.target.classList.contains('page-link')) {
      e.preventDefault();
      const page = e.target.getAttribute('data-page');
      if (page) {
        currentPage = parseInt(page);
        loadLogs();

        const tableResponsive = document.querySelector('.table-responsive');
        if (tableResponsive) {
          tableResponsive.scrollIntoView({
            behavior: 'smooth'
          });
        }
      }
    }
  });

  // Load logs data
  function loadLogs() {
    const tableBody = document.getElementById('logs-table');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="7" class="text-center">Loading logs...</td></tr>';

    // Build query parameters using active filters
    const params = new URLSearchParams();

    // Add all active filters to params
    Object.entries(activeFilters).forEach(([key, value]) => {
      params.append(key, value);
    });

    params.append('per_page', perPage);
    params.append('page', currentPage);

    console.log('Fetching logs with params:', Object.fromEntries(params));

    // Fetch data from API
    fetch(`/api/web/dashboard/logs?${params.toString()}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`Network response was not ok: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Received logs data:', data);

        // Update statistics
        updateStatistics(data.stats);

        if (!data.data || data.data.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No logs found</td></tr>';
          const pagination = document.getElementById('pagination');
          if (pagination) pagination.innerHTML = '';
          return;
        }

        // Populate table
        let html = '';
        data.data.forEach(log => {
          html += `
                        <tr class="${getSeverityRowClass(log.severity || 'info')}">
                            <td>${formatDate(log.created_at)}</td>
                            <td>${log.log_type || 'general'}</td>
                            <td>
                                <span class="badge ${getSeverityBadgeClass(log.severity || 'info')}">
                                    ${log.severity || 'info'}
                                </span>
                            </td>
                            <td>${(log.posTerminal && log.posTerminal.terminal_uid) || 'N/A'}</td>
                            <td title="${log.message || 'No message'}">${truncateText(log.message, 50) || 'N/A'}</td>
                            <td>${log.transaction_id || 'N/A'}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info view-log-btn" 
                                        data-log='${JSON.stringify(log)}'>
                                    View
                                </button>
                            </td>
                        </tr>
                    `;
        });
        tableBody.innerHTML = html;

        // Update pagination
        if (data.meta) {
          renderPagination(data.meta);
        }
      })
      .catch(error => {
        console.error('Error fetching logs:', error);
        tableBody.innerHTML =
          '<tr><td colspan="7" class="text-center text-danger">Error loading logs. Please try again later.</td></tr>';
      });
  }

  // Update statistics section
  function updateStatistics(stats) {
    if (!stats) return;

    document.getElementById('total-logs').textContent = stats.total_logs ?? 0;
    document.getElementById('error-count').textContent = stats.error_count ?? 0;
    document.getElementById('warning-count').textContent = stats.warning_count ?? 0;
    document.getElementById('info-count').textContent = stats.info_count ?? 0;
    document.getElementById('latest-error').textContent = stats.latest_error ?? 'Never';
    document.getElementById('logs-today').textContent = stats.logs_today ?? 0;
  }

  // Handle exports
  function exportLogs(format) {
    const exportForm = document.getElementById('export-form');
    const exportFormat = document.getElementById('export-format');

    // Set the export format
    exportFormat.value = format;

    // Clear any existing filter inputs
    const existingInputs = exportForm.querySelectorAll('input[name^="filter_"]');
    existingInputs.forEach(input => input.remove());

    // Add all active filters as hidden inputs
    Object.entries(activeFilters).forEach(([key, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = value;
      exportForm.appendChild(input);
    });

    // Submit the form
    exportForm.submit();
  }

  // Show log detail modal
  function showLogDetailModal(log) {
    if (!logDetailModal) return;

    // Populate modal fields
    document.getElementById('modal-time').textContent = formatDate(log.created_at);
    document.getElementById('modal-type').textContent = log.log_type || 'general';
    document.getElementById('modal-severity').textContent = log.severity || 'info';
    document.getElementById('modal-message').textContent = log.message || 'No message';

    try {
      const context = typeof log.context === 'string' ?
        JSON.parse(log.context) :
        log.context;
      document.getElementById('modal-context').textContent = JSON.stringify(context, null, 2);
    } catch (e) {
      document.getElementById('modal-context').textContent = log.context || 'No context';
    }

    document.getElementById('modal-transaction-id').textContent = log.transaction_id || 'N/A';
    document.getElementById('modal-terminal').textContent = (log.posTerminal && log.posTerminal.terminal_uid) ||
      'N/A';
    document.getElementById('modal-user').textContent = (log.user && `${log.user.name} (${log.user.email})`) ||
      'N/A';
    document.getElementById('modal-tenant').textContent = (log.tenant && log.tenant.name) || 'N/A';

    // Show the modal
    logDetailModal.show();
  }

  // Render pagination links
  function renderPagination(meta) {
    const pagination = document.getElementById('pagination');
    if (!pagination || !meta || !meta.last_page) {
      if (pagination) pagination.innerHTML = '';
      return;
    }

    let html = '';

    // Previous button
    html += `
            <li class="page-item ${meta.current_page === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${meta.current_page - 1}" aria-label="Previous">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
        `;

    // Page numbers
    for (let i = 1; i <= meta.last_page; i++) {
      // Show limited number of pages for better UX
      if (i === 1 || i === meta.last_page || (i >= meta.current_page - 2 && i <= meta.current_page + 2)) {
        html += `
                    <li class="page-item ${i === meta.current_page ? 'active' : ''}">
                        <a class="page-link" href="#" data-page="${i}">${i}</a>
                    </li>
                `;
      } else if (i === meta.current_page - 3 || i === meta.current_page + 3) {
        html += `
                    <li class="page-item disabled">
                        <a class="page-link" href="#">...</a>
                    </li>
                `;
      }
    }

    // Next button
    html += `
            <li class="page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${meta.current_page + 1}" aria-label="Next">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        `;

    pagination.innerHTML = html;
  }

  // Helper functions
  function getSeverityRowClass(severity) {
    switch (severity.toLowerCase()) {
      case 'error':
        return 'table-danger';
      case 'warning':
        return 'table-warning';
      case 'info':
        return '';
      case 'debug':
        return 'table-secondary';
      default:
        return '';
    }
  }

  function getSeverityBadgeClass(severity) {
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
        return 'bg-info';
    }
  }

  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
      const date = new Date(dateString);
      return isNaN(date.getTime()) ? 'Invalid Date' : date.toLocaleString();
    } catch (e) {
      return 'Invalid Date';
    }
  }

  function truncateText(text, maxLength) {
    if (!text) return null;
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
  }

  // Toggle advanced search panel
  document.getElementById('toggle-advanced-search').addEventListener('click', function() {
    const panel = document.getElementById('advanced-search-panel');
    if (panel.style.display === 'none') {
      panel.style.display = 'block';
      this.textContent = 'Hide Advanced Search';
    } else {
      panel.style.display = 'none';
      this.textContent = 'Advanced Search';
    }
  });

  // Live updates toggle
  let eventSource = null;
  document.getElementById('live-updates').addEventListener('change', function() {
    if (this.checked) {
      startLiveUpdates();
    } else {
      stopLiveUpdates();
    }
  });

  // Start SSE connection for live updates
  function startLiveUpdates() {
    if (eventSource) {
      eventSource.close();
    }

    // Build parameters from filters
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(activeFilters)) {
      if (value) {
        params.append(key, value);
      }
    }

    // Connect to the SSE endpoint
    eventSource = new EventSource(`/api/web/dashboard/logs/stream?${params.toString()}`);

    // Handle log events
    eventSource.addEventListener('logs', function(e) {
      const data = JSON.parse(e.data);
      if (data.logs && data.logs.length > 0) {
        // Prepend new logs to the table
        prependLogs(data.logs);

        // Update the statistics
        const statsRequest = new XMLHttpRequest();
        statsRequest.open('GET', `/api/web/dashboard/logs/stats?${params.toString()}`);
        statsRequest.onload = function() {
          if (this.status === 200) {
            updateStatistics(JSON.parse(this.response));
          }
        };
        statsRequest.send();
      }
    });

    // Handle ping events
    eventSource.addEventListener('ping', function(e) {
      console.log('Ping received from server');
    });

    // Handle errors
    eventSource.onerror = function() {
      console.error('SSE connection error');
      stopLiveUpdates();
      document.getElementById('live-updates').checked = false;
    };
  }

  // Stop SSE connection
  function stopLiveUpdates() {
    if (eventSource) {
      eventSource.close();
      eventSource = null;
    }
  }

  // Prepend new logs to the table
  function prependLogs(logs) {
    const tableBody = document.getElementById('logs-table');
    if (!tableBody) return;

    logs.forEach(log => {
      // Create a new row
      const row = document.createElement('tr');
      row.className = getSeverityRowClass(log.severity || 'info');
      row.id = `log-${log.id}`;

      // Add highlight effect
      row.style.animation = 'highlight 3s';

      // Add cells
      row.innerHTML = `
                <td>${formatDate(log.created_at)}</td>
                <td>${log.log_type || 'general'}</td>
                <td>
                    <span class="badge ${getSeverityBadgeClass(log.severity || 'info')}">
                        ${log.severity || 'info'}
                    </span>
                </td>
                <td>${(log.posTerminal && log.posTerminal.terminal_uid) || 'N/A'}</td>
                <td title="${log.message || 'No message'}">${truncateText(log.message, 50) || 'N/A'}</td>
                <td>${log.transaction_id || 'N/A'}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-info view-log-btn" 
                            data-log='${JSON.stringify(log)}'>
                        View
                    </button>
                </td>
            `;

      // Add to top of table
      if (tableBody.firstChild) {
        tableBody.insertBefore(row, tableBody.firstChild);
      } else {
        tableBody.appendChild(row);
      }
    });
  }

  // When the page is unloaded, close the SSE connection
  window.addEventListener('beforeunload', function() {
    stopLiveUpdates();
  });
});
</script>

<style>
@keyframes highlight {
  0% {
    background-color: rgba(255, 255, 0, 0.5);
  }

  100% {
    background-color: transparent;
  }
}
</style>
@endsection