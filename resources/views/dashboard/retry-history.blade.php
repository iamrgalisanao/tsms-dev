@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <h5>Retry History</h5>
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
    <!-- Analytics Overview Panel -->
    <div class="row mb-4">
      <div class="col-md-12">
        <div class="card">
          <div class="card-header bg-light">
            <h6 class="mb-0">Retry Analytics Overview</h6>
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
                <h3 id="avg-response-time">--</h3>
                <p class="text-muted">Avg Response Time</p>
              </div>
              <div class="col-md-3 text-center">
                <h3 id="active-circuit-breakers">--</h3>
                <p class="text-muted">Active Circuit Breakers</p>
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
            <h6 class="mb-0">Filters</h6>
          </div>
          <div class="card-body">
            <form id="filter-form" class="row g-3">
              <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                  <option value="">All Statuses</option>
                  <option value="SUCCESS">Success</option>
                  <option value="FAILED">Failed</option>
                  <option value="PENDING">Pending</option>
                </select>
              </div>
              <div class="col-md-3">
                <label for="terminal_id" class="form-label">POS Terminal</label>
                <select class="form-select" id="terminal_id" name="terminal_id">
                  <option value="">All Terminals</option>
                  @foreach($terminals as $terminal)
                  <option value="{{ $terminal->id }}">{{ $terminal->terminal_uid }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-3">
                <label for="date_from" class="form-label">Date From</label>
                <input type="date" class="form-control" id="date_from" name="date_from">
              </div>
              <div class="col-md-3">
                <label for="date_to" class="form-label">Date To</label>
                <input type="date" class="form-control" id="date_to" name="date_to">
              </div>
              <div class="col-12 mt-3">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <button type="button" id="reset-filters" class="btn btn-outline-secondary">Reset</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Retry History Table -->
    <div class="table-responsive">
      <table class="table table-striped table-hover">
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>Terminal</th>
            <th>Status</th>
            <th>Retry Count</th>
            <th>Last Retry</th>
            <th>Reason</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="retry-history-table">
          <tr>
            <td colspan="7" class="text-center">Loading retry history...</td>
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
      <nav aria-label="Retry history pagination">
        <ul class="pagination" id="pagination">
          <!-- Pagination links will be generated here -->
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Retry Confirmation Modal -->
<div class="modal fade" id="retryModal" tabindex="-1" aria-labelledby="retryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="retryModalLabel">Confirm Retry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to retry transaction <strong id="retry-transaction-id"></strong>?</p>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i> This action will queue a new retry attempt for this transaction.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <form id="retry-form" method="POST">
          @csrf
          <button type="submit" class="btn btn-primary">Retry Transaction</button>
        </form>
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

  // Initialize Bootstrap Modal
  let retryModal = null;
  const retryModalEl = document.getElementById('retryModal');
  if (retryModalEl && typeof bootstrap !== 'undefined') {
    retryModal = new bootstrap.Modal(retryModalEl);
  }

  // Initial load
  loadRetryHistory();
  loadAnalytics();

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
    loadRetryHistory();
    loadAnalytics();
  });

  // Reset filters
  document.getElementById('reset-filters').addEventListener('click', function() {
    document.getElementById('filter-form').reset();
    activeFilters = {}; // Clear active filters
    currentPage = 1;
    loadRetryHistory();
    loadAnalytics();
  });

  // Per page change
  document.getElementById('per-page').addEventListener('change', function() {
    perPage = parseInt(this.value);
    currentPage = 1; // Reset to first page when changing items per page
    loadRetryHistory();
  });

  // Event delegation for dynamic elements
  document.addEventListener('click', function(e) {
    // Handle retry button clicks
    if (e.target.classList.contains('retry-btn')) {
      const transactionId = e.target.getAttribute('data-transaction-id');
      const logId = e.target.getAttribute('data-log-id');

      const rtId = document.getElementById('retry-transaction-id');
      if (rtId) rtId.textContent = transactionId;

      const form = document.getElementById('retry-form');
      if (form) form.action = `/dashboard/retry-history/${logId}/retry`;

      if (retryModal) retryModal.show();
    }

    // Handle pagination clicks
    if (e.target.classList.contains('page-link')) {
      e.preventDefault();
      const page = e.target.getAttribute('data-page');
      if (page) {
        currentPage = parseInt(page);
        loadRetryHistory();

        const tableResponsive = document.querySelector('.table-responsive');
        if (tableResponsive) {
          tableResponsive.scrollIntoView({
            behavior: 'smooth'
          });
        }
      }
    }
  });

  // Load retry history data
  function loadRetryHistory() {
    const tableBody = document.getElementById('retry-history-table');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="7" class="text-center">Loading retry history...</td></tr>';

    // Build query parameters using active filters
    const params = new URLSearchParams();

    // Add all active filters to params
    Object.entries(activeFilters).forEach(([key, value]) => {
      params.append(key, value);
    });

    params.append('per_page', perPage);
    params.append('page', currentPage);

    console.log('Fetching retry history with params:', Object.fromEntries(params));

    // Fetch data from API
    fetch(`/api/web/dashboard/retry-history?${params.toString()}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`Network response was not ok: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Received retry history data:', data);

        if (!data.data || data.data.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No retry history found</td></tr>';
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
                            <td>${(log.posTerminal && log.posTerminal.terminal_uid) || 'Unknown'}</td>
                            <td>
                                <span class="badge ${getStatusBadgeClass(log.status || 'UNKNOWN')}">
                                    ${log.status || 'Unknown'}
                                </span>
                            </td>
                            <td>${log.retry_count || 0}</td>
                            <td>${formatDate(log.last_retry_at) || 'N/A'}</td>
                            <td title="${log.retry_reason || 'No reason provided'}">${truncateText(log.retry_reason, 30) || 'N/A'}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="/dashboard/retry-history/${log.id}" class="btn btn-sm btn-info">
                                        Details
                                    </a>
                                    <button type="button" class="btn btn-sm btn-primary retry-btn" 
                                            data-transaction-id="${log.transaction_id || ''}" 
                                            data-log-id="${log.id || ''}">
                                        Retry
                                    </button>
                                </div>
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
        console.error('Error fetching retry history:', error);
        tableBody.innerHTML =
          '<tr><td colspan="7" class="text-center text-danger">Error loading retry history. Please try again later.</td></tr>';
      });
  }

  // Load analytics data
  function loadAnalytics() {
    // Build query parameters from active filters
    const params = new URLSearchParams();

    // Add all active filters to params
    Object.entries(activeFilters).forEach(([key, value]) => {
      params.append(key, value);
    });

    // Update analytics placeholders
    document.getElementById('total-retries').textContent = 'Loading...';
    document.getElementById('success-rate').textContent = 'Loading...';
    document.getElementById('avg-response-time').textContent = 'Loading...';
    document.getElementById('active-circuit-breakers').textContent = 'Loading...';

    // Fetch analytics from API
    fetch(`/api/web/dashboard/retry-history/analytics?${params.toString()}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`Network response was not ok: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Received analytics data:', data);

        document.getElementById('total-retries').textContent = data.total_retries !== undefined ? data
          .total_retries : 0;
        document.getElementById('success-rate').textContent =
          `${data.success_rate !== undefined ? data.success_rate : 0}%`;
        document.getElementById('avg-response-time').textContent = formatResponseTime(data.avg_response_time);

        // Get circuit breaker status if endpoint exists
        try {
          fetch('/api/web/circuit-breaker/states')
            .then(response => {
              if (!response.ok) throw new Error('Circuit breaker endpoint not available');
              return response.json();
            })
            .then(cbData => {
              const openBreakers = Object.values(cbData).filter(state => state === 'open').length;
              document.getElementById('active-circuit-breakers').textContent = openBreakers;
            })
            .catch(error => {
              console.warn('Could not fetch circuit breaker states:', error);
              document.getElementById('active-circuit-breakers').textContent = 'N/A';
            });
        } catch (e) {
          console.warn('Circuit breaker fetch error:', e);
          document.getElementById('active-circuit-breakers').textContent = 'N/A';
        }
      })
      .catch(error => {
        console.error('Error fetching analytics:', error);
        document.getElementById('total-retries').textContent = 'Error';
        document.getElementById('success-rate').textContent = 'Error';
        document.getElementById('avg-response-time').textContent = 'Error';
        document.getElementById('active-circuit-breakers').textContent = 'Error';
      });
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
  function getStatusBadgeClass(status) {
    switch (status) {
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

  function formatDate(dateString) {
    if (!dateString) return null;
    try {
      const date = new Date(dateString);
      return isNaN(date.getTime()) ? 'Invalid Date' : date.toLocaleString();
    } catch (e) {
      return 'Invalid Date';
    }
  }

  function formatResponseTime(time) {
    if (!time) return 'N/A';
    return `${Math.round((time + Number.EPSILON) * 100) / 100}ms`;
  }

  function truncateText(text, maxLength) {
    if (!text) return null;
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
  }
});
</script>
@endsection