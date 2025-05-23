@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header">
    <h5>Retry History</h5>
  </div>
  <div class="card-body">
    <!-- Filters -->
    <div class="mb-6">
      <form method="GET" action="{{ route('dashboard.retry-history') }}" class="row g-3">
        <div class="col-md-4">
          <label for="terminal_id" class="form-label">Terminal</label>
          <select name="terminal_id" id="terminal_id" class="form-select">
            <option value="">All Terminals</option>
            @foreach($terminals as $terminal)
            <option value="{{ $terminal->id }}" {{ request('terminal_id') == $terminal->id ? 'selected' : '' }}>
              {{ $terminal->terminal_uid }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-4">
          <label for="date_from" class="form-label">Date From</label>
          <input type="date" name="date_from" id="date_from" value="{{ request('date_from') }}" class="form-control">
        </div>

        <div class="col-md-4">
          <label for="date_to" class="form-label">Date To</label>
          <input type="date" name="date_to" id="date_to" value="{{ request('date_to') }}" class="form-control">
        </div>

        <div class="col-12">
          <div class="d-flex">
            <button type="submit" class="btn btn-primary me-2">
              Filter
            </button>
            <a href="{{ route('dashboard.retry-history') }}" class="btn btn-secondary">
              Reset
            </a>
          </div>
        </div>
      </form>
    </div>

    <!-- Retry Analytics -->
    <div class="card mb-4 mt-4">
      <div class="card-header bg-light">
        <h6 class="m-0">Retry Analytics</h6>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-3 text-center">
            <h3 id="total-retries">--</h3>
            <p class="text-muted">Total Retries</p>
          </div>
          <div class="col-md-3 text-center">
            <h3 id="success-rate">--</h3>
            <p class="text-muted">Success Rate</p>
          </div>
          <div class="col-md-3 text-center">
            <h3 id="avg-retry-time">--</h3>
            <p class="text-muted">Avg Response Time</p>
          </div>
          <div class="col-md-3 text-center">
            <h3 id="retry-growth">--</h3>
            <p class="text-muted">Retries Today</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Retry History Table -->
    <div class="table-responsive">
      <table class="table table-striped" id="retry-history-table">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Terminal</th>
            <th>Retry Count</th>
            <th>Last Retry</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colspan="6" class="text-center">Loading retry history...</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination controls -->
    <div class="d-flex justify-content-between align-items-center mt-4">
      <div>
        <select id="per-page" class="form-select form-select-sm">
          <option value="10">10 per page</option>
          <option value="25">25 per page</option>
          <option value="50">50 per page</option>
        </select>
      </div>
      <nav aria-label="Retry history pagination">
        <ul class="pagination" id="pagination">
          <!-- Pagination links will be generated via JavaScript -->
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Retry Detail Modal -->
<div class="modal fade" id="retryDetailModal" tabindex="-1" aria-labelledby="retryDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="retryDetailModalLabel">Retry Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold">Transaction ID:</label>
          <p id="modal-transaction-id"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Terminal:</label>
          <p id="modal-terminal"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Retry Count:</label>
          <p id="modal-retry-count"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Status:</label>
          <p id="modal-status"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Last Retry At:</label>
          <p id="modal-last-retry"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Retry Reason:</label>
          <p id="modal-retry-reason"></p>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Response Time:</label>
          <p id="modal-response-time"></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="retry-transaction-btn">Retry Again</button>
      </div>
    </div>
  </div>
</div>

<!-- JS for handling retry history -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  let currentPage = 1;
  let perPage = 10;
  let activeFilters = {}; // Store active filters
  let currentLogId = null; // Store the current log ID for retrying

  // Fetch retry history data
  function fetchRetryHistory() {
    const tableBody = document.querySelector('#retry-history-table tbody');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="6" class="text-center">Loading retry history...</td></tr>';

    // Build query parameters
    const params = new URLSearchParams();

    // Add page and per_page
    params.append('page', currentPage);
    params.append('per_page', perPage);

    // Add all active filters
    Object.entries(activeFilters).forEach(([key, value]) => {
      if (value) params.append(key, value);
    });

    // Fetch data from API - Changed URL to use the correct API endpoint
    fetch(`/api/web/dashboard/retry-history?${params.toString()}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        // Update analytics first
        updateAnalytics(data.analytics);

        // Check if we have data
        if (!data.data || data.data.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No retry history found</td></tr>';
          const pagination = document.getElementById('pagination');
          if (pagination) pagination.innerHTML = '';
          return;
        }

        // Populate table
        let html = '';
        data.data.forEach(log => {
          html += `
            <tr>
              <td>${log.transaction_id || 'N/A'}</td>
              <td>${log.posTerminal ? log.posTerminal.terminal_uid : 'N/A'}</td>
              <td>${log.retry_count || '0'}</td>
              <td>${formatDate(log.last_retry_at) || 'N/A'}</td>
              <td><span class="badge ${getStatusBadgeClass(log.status)}">${log.status || 'UNKNOWN'}</span></td>
              <td>
                <button class="btn btn-sm btn-info view-detail-btn" data-id="${log.id}" 
                  data-log='${JSON.stringify(log)}'>View</button>
                <button class="btn btn-sm btn-primary retry-btn" data-id="${log.id}">Retry</button>
              </td>
            </tr>
          `;
        });

        tableBody.innerHTML = html;

        // Update pagination
        if (data.meta) {
          renderPagination(data.meta);
        }

        // Add event listeners to buttons
        addButtonListeners();
      })
      .catch(error => {
        console.error('Error fetching retry history:', error);
        tableBody.innerHTML =
          '<tr><td colspan="6" class="text-center text-danger">Error loading retry history</td></tr>';
      });
  }

  // Update analytics section
  function updateAnalytics(analytics) {
    if (!analytics) return;

    document.getElementById('total-retries').textContent = analytics.total_retries || '0';
    document.getElementById('success-rate').textContent = `${analytics.success_rate || '0'}%`;
    document.getElementById('avg-retry-time').textContent = `${(analytics.avg_response_time || 0).toFixed(2)}ms`;
    document.getElementById('retry-growth').textContent = analytics.retries_today || '0';
  }

  // Format date helper
  function formatDate(dateString) {
    if (!dateString) return null;

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'Invalid Date';

    return date.toLocaleString();
  }

  // Get status badge class
  function getStatusBadgeClass(status) {
    if (!status) return 'bg-secondary';

    switch (status.toUpperCase()) {
      case 'SUCCESS':
        return 'bg-success';
      case 'FAILED':
        return 'bg-danger';
      case 'PENDING':
        return 'bg-warning';
      default:
        return 'bg-secondary';
    }
  }

  // Render pagination
  function renderPagination(meta) {
    const pagination = document.getElementById('pagination');
    if (!pagination) return;

    let html = '';

    // Previous button
    html += `
      <li class="page-item ${meta.current_page === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" data-page="${meta.current_page - 1}">Previous</a>
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
        <a class="page-link" href="#" data-page="${meta.current_page + 1}">Next</a>
      </li>
    `;

    pagination.innerHTML = html;

    // Add event listeners to pagination links
    document.querySelectorAll('#pagination .page-link').forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();

        const page = this.getAttribute('data-page');
        if (page && !isNaN(parseInt(page))) {
          currentPage = parseInt(page);
          fetchRetryHistory();
        }
      });
    });
  }

  // Add event listeners to table buttons
  function addButtonListeners() {
    // View detail buttons
    document.querySelectorAll('.view-detail-btn').forEach(button => {
      button.addEventListener('click', function() {
        const logData = JSON.parse(this.getAttribute('data-log'));
        showDetailModal(logData);
      });
    });

    // Retry buttons
    document.querySelectorAll('.retry-btn').forEach(button => {
      button.addEventListener('click', function() {
        const logId = this.getAttribute('data-id');
        retryTransaction(logId);
      });
    });
  }

  // Show detail modal
  function showDetailModal(log) {
    // Set current log ID for retry button
    currentLogId = log.id;

    // Populate modal fields
    document.getElementById('modal-transaction-id').textContent = log.transaction_id || 'N/A';
    document.getElementById('modal-terminal').textContent =
      (log.posTerminal ? log.posTerminal.terminal_uid : 'N/A');
    document.getElementById('modal-retry-count').textContent = log.retry_count || '0';
    document.getElementById('modal-status').textContent = log.status || 'UNKNOWN';
    document.getElementById('modal-last-retry').textContent = formatDate(log.last_retry_at) || 'N/A';
    document.getElementById('modal-retry-reason').textContent = log.retry_reason || 'N/A';
    document.getElementById('modal-response-time').textContent =
      log.response_time ? `${log.response_time}ms` : 'N/A';

    // Initialize and show modal
    const modal = new bootstrap.Modal(document.getElementById('retryDetailModal'));
    modal.show();
  }

  // Retry transaction - Updated to use correct API endpoint
  function retryTransaction(logId) {
    if (!logId) return;

    // Disable the button to prevent multiple clicks
    const button = document.querySelector(`.retry-btn[data-id="${logId}"]`);
    if (button) {
      button.disabled = true;
      button.textContent = 'Retrying...';
    }

    // Call the retry API with the corrected URL
    fetch(`/api/web/dashboard/retry-history/${logId}/retry`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          alert('Transaction has been queued for retry');
          fetchRetryHistory(); // Refresh the data
        } else {
          alert('Error: ' + (data.message || 'Failed to retry transaction'));
        }
      })
      .catch(error => {
        console.error('Error retrying transaction:', error);
        alert('An error occurred while retrying the transaction.');
      })
      .finally(() => {
        // Re-enable the button
        if (button) {
          button.disabled = false;
          button.textContent = 'Retry';
        }
      });
  }

  // Initialize
  function init() {
    // Fetch initial data
    fetchRetryHistory();

    // Set up per-page change handler
    document.getElementById('per-page').addEventListener('change', function() {
      perPage = parseInt(this.value);
      currentPage = 1; // Reset to page 1
      fetchRetryHistory();
    });

    // Set up filter form
    const filterForm = document.querySelector('form');
    if (filterForm) {
      filterForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        activeFilters = {};

        for (const [key, value] of formData.entries()) {
          if (value) activeFilters[key] = value;
        }

        currentPage = 1; // Reset to page 1
        fetchRetryHistory();
      });
    }

    // Set up retry button in modal
    document.getElementById('retry-transaction-btn').addEventListener('click', function() {
      if (currentLogId) {
        retryTransaction(currentLogId);

        // Close the modal
        const modalElement = document.getElementById('retryDetailModal');
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();
      }
    });
  }

  // Initialize everything
  init();
});
</script>
@endsection