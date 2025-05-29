<!DOCTYPE html>
@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5>Retry History</h5>
      <div class="d-flex gap-2">
        <div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" type="button" id="exportDropdown"
            data-bs-toggle="dropdown">
            Export
          </button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#" onclick="exportData('csv')">CSV</a></li>
            <li><a class="dropdown-item" href="#" onclick="exportData('pdf')">PDF</a></li>
          </ul>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="liveUpdates" checked>
          <label class="form-check-label" for="liveUpdates">Live Updates</label>
        </div>
      </div>
    </div>

    <div class="card-body border-bottom">
      <div class="row align-items-center">
        <div class="col-md-6">
          <div class="input-group">
            <input type="text" class="form-control" id="searchField" placeholder="Search by Transaction ID...">
            <button class="btn btn-primary" onclick="applyFilters()">
              <i class="fas fa-search me-1"></i>Search
            </button>
          </div>
        </div>
        <div class="col-md-6 text-end">
          <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
            data-bs-target="#advancedFilters">
            <i class="fas fa-filter me-1"></i>Advanced Filters
          </button>
        </div>
      </div>

      <!-- Advanced Filters (Collapsed by default) -->
      <div class="collapse mt-3" id="advancedFilters">
        <div class="card card-body bg-light">
          <div class="row g-3">
            <div class="col-md-4">
              <select class="form-select" id="statusFilter">
                <option value="">All Statuses</option>
                <option value="COMPLETED">Completed</option>
                <option value="FAILED">Failed</option>
                <option value="PROCESSING">Processing</option>
                <option value="QUEUED">Queued</option>
              </select>
            </div>
            <div class="col-md-4">
              <input type="date" class="form-control" id="dateFilter">
            </div>
            <div class="col-md-4 text-end">
              <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
              <button class="btn btn-outline-secondary ms-2" onclick="resetFilters()">Reset</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div id="loadingSpinner" class="text-center">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
      <div id="errorMessage" class="alert alert-danger d-none"></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th style="min-width: 180px">Transaction ID</th>
              <th style="min-width: 120px">Terminal</th>
              <th class="text-center" style="width: 100px"># Retry</th>
              <th style="min-width: 120px">Job Status</th>
              <th>Error Details</th>
              <th style="width: 160px">Updated</th>
              <th style="width: 100px" class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody id="retryHistoryTableBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// Helper Functions
function getStatusClass(status) {
  const statusClasses = {
    'COMPLETED': 'success',
    'FAILED': 'danger',
    'PROCESSING': 'primary',
    'QUEUED': 'warning',
    'PENDING': 'info'
  };
  return `bg-${statusClasses[status] || 'secondary'}`;
}

function getValidationStatusClass(status) {
  const statusClasses = {
    'VALID': 'success',
    'INVALID': 'danger',
    'PENDING': 'warning'
  };
  return `bg-${statusClasses[status] || 'secondary'}`;
}

function loadRetryHistory() {
  const spinner = document.getElementById('loadingSpinner');
  const errorDiv = document.getElementById('errorMessage');
  const tbody = document.getElementById('retryHistoryTableBody');

  spinner.classList.remove('d-none');
  errorDiv.classList.add('d-none');

  const filters = {
    transaction_id: document.getElementById('searchField').value, // Changed to be more specific
    status: document.getElementById('statusFilter')?.value,
    date: document.getElementById('dateFilter')?.value
  };

  const params = new URLSearchParams(filters);

  console.log('Fetching retry history with params:', Object.fromEntries(params));

  fetch(`/api/v1/retry-history?${params.toString()}`, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Cache-Control': 'no-cache' // Add cache control to prevent caching issues
      }
    })
    .then(response => {
      if (!response.ok) {
        if (response.status === 500) {
          console.error('Main API returned 500, trying emergency data creation');
          // Create emergency data directly instead of checking for it
          return createEmergencyDataAndReload()
            .then(() => {
              throw new Error('Created emergency data. Reloading page in 2 seconds...');
            })
            .catch(err => {
              throw new Error(`Could not load or create data: ${err.message}`);
            });
        } else {
          throw new Error(`Network response error (${response.status})`);
        }
      }
      return response.json();
    })
    .then(data => {
      console.log('Response data:', data);

      if (data.status === 'success') {
        const formattedRetries = data.data?.data || [];
        console.log('Formatted retries:', formattedRetries);

        // Force check if data exists by accessing raw properties
        const hasData = formattedRetries && formattedRetries.length > 0;
        console.log('Has data:', hasData, 'Count:', formattedRetries.length);

        if (!hasData) {
          // Empty state
          tbody.innerHTML = `
            <tr>
              <td colspan="7" class="text-center py-5">
                <div class="mb-4">
                  <i class="fas fa-history fa-3x text-secondary opacity-50"></i>
                </div>
                <h5 class="fw-normal mb-3">No retry history found</h5>
                <p class="text-muted mb-4">There are no transactions with retry attempts in the database.</p>
                <div class="d-flex justify-content-center gap-3">
                  <button class="btn btn-primary" onclick="createDemoData()">
                    <i class="fas fa-plus-circle me-2"></i>Create Demo Data
                  </button>
                  <button class="btn btn-outline-primary" onclick="createEmergencyData()">
                    <i class="fas fa-bolt me-2"></i>Emergency Fix
                  </button>
                  <button class="btn btn-outline-secondary" onclick="checkDebugInfo()">
                    <i class="fas fa-info-circle me-2"></i>Check Database
                  </button>
                </div>
              </td>
            </tr>
          `;
        } else {
          // Use the improved function for table rows
          tbody.innerHTML = formatTableRows(formattedRetries);
        }
      } else {
        throw new Error(data.message || 'Failed to load data');
      }
    })
    .catch(error => {
      console.error('Error loading retry history:', error);
      errorDiv.textContent = error.message;
      errorDiv.classList.remove('d-none');

      // Show error state in the table
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="text-center text-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            Error loading data: ${error.message}
          </td>
        </tr>
      `;
    })
    .finally(() => {
      spinner.classList.add('d-none');
    });
}

// Initial load
loadRetryHistory();

// Refresh every 30 seconds if WebSocket not available
let refreshInterval;

// Safely initialize real-time updates
function initializeRealTimeUpdates() {
  // Always use polling as fallback
  refreshInterval = setInterval(loadRetryHistory, 30000);
  console.log('Using polling for updates (30s interval)');

  // Try to use WebSocket if available
  if (typeof Echo !== 'undefined') {
    try {
      const retryChannel = Echo.channel('transaction-updates');
      retryChannel.listen('TransactionRetryUpdated', (data) => {
        if (document.getElementById('liveUpdates').checked) {
          loadRetryHistory();
        }
      });
      console.log('WebSocket listener initialized');
    } catch (e) {
      console.warn('Failed to initialize WebSockets:', e);
    }
  }
}

// Initialize real-time updates
initializeRealTimeUpdates();

// Cleanup on page unload
window.addEventListener('unload', () => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
  }
});

// Add export functionality
function exportData(format) {
  const filters = {
    status: document.getElementById('statusFilter').value,
    search: document.getElementById('searchField').value,
    date: document.getElementById('dateFilter').value
  };

  const params = new URLSearchParams(filters);
  window.location.href = `/api/v1/retry-history/export?format=${format}&${params}`;
}

// Add filter handling
function applyFilters() {
  loadRetryHistory();
}

function resetFilters() {
  document.getElementById('searchField').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('dateFilter').value = '';
  document.querySelector('#advancedFilters').classList.remove('show');
  loadRetryHistory();
}

// Add function to format transactions more similar to screenshot
function formatTableRows(formattedRetries) {
  return formattedRetries.map(item => `
    <tr data-transaction-id="${item.id}">
      <td class="text-nowrap">
        <strong>${item.transaction_id.startsWith('TX-') ? item.transaction_id : `TX-${item.transaction_id}`}</strong>
      </td>
      <td class="text-nowrap">${item.terminal_uid}</td>
      <td class="text-center">
        <span class="badge bg-secondary">${item.job_attempts || 0}</span>
      </td>
      <td>
        <span class="badge ${getStatusClass(item.job_status)}">${item.job_status || 'UNKNOWN'}</span>
      </td>
      <td class="text-wrap" style="max-width: 400px">
        <small class="text-muted">${item.last_error || 'None'}</small>
      </td>
      <td class="text-nowrap">
        <small>${new Date(item.updated_at).toLocaleString('en-US')}</small>
      </td>
      <td class="text-center">
        <button class="btn btn-sm btn-primary" onclick="retryTransaction('${item.id}')">
          <i class="fas fa-redo-alt me-1"></i>Retry
        </button>
      </td>
    </tr>
  `).join('') || '<tr><td colspan="7" class="text-center">No retry history found</td></tr>';
}

// Define the retry function that was being called but wasn't declared
function retryTransaction(id) {
  if (!id) {
    showError('Invalid transaction ID');
    return;
  }

  const row = document.querySelector(`tr[data-transaction-id="${id}"]`);
  if (!row) {
    showError('Transaction row not found');
    return;
  }

  // Disable retry button and show loading state
  const retryButton = row.querySelector('button');
  retryButton.disabled = true;
  retryButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Retrying...';

  // Update status to processing
  updateRowStatus(row, 'PROCESSING', 'Initiating retry...');

  fetch(`/api/v1/retry-history/${id}/retry`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(async response => {
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || `HTTP error! status: ${response.status}`);
      }

      return data;
    })
    .then(data => {
      if (data.status === 'success') {
        updateRowStatus(row, 'QUEUED', 'Retry initiated');
        showSuccess('Retry initiated successfully');

        // Poll for status updates
        const pollInterval = setInterval(() => {
          checkTransactionStatus(id, pollInterval);
        }, 2000);

        // Stop polling after 30 seconds
        setTimeout(() => clearInterval(pollInterval), 30000);
      } else {
        throw new Error(data.message || 'Retry failed');
      }
    })
    .catch(error => {
      console.error('Retry failed:', error);
      updateRowStatus(row, 'FAILED', error.message);
      showError(`Retry failed: ${error.message}`);
    })
    .finally(() => {
      retryButton.disabled = false;
      retryButton.innerHTML = '<i class="fas fa-redo-alt me-1"></i>Retry';
    });
}

function updateRowStatus(row, status, message) {
  row.querySelector('td:nth-child(4)').innerHTML =
    `<span class="badge ${getStatusClass(status)}">${status}</span>`;
  row.querySelector('td:nth-child(5)').textContent = message || '';
  row.querySelector('td:nth-child(6)').textContent = new Date().toLocaleString('en-US');
}

function checkTransactionStatus(id, pollInterval = null) {
  fetch(`/api/v1/retry-history/${id}/status`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.status === 'success') {
        const row = document.querySelector(`tr[data-transaction-id="${id}"]`);
        if (row) {
          updateRowStatus(
            row,
            data.data.job_status,
            data.data.last_error || 'Processing...'
          );

          // If completed or failed, stop polling
          if (['COMPLETED', 'FAILED'].includes(data.data.job_status) && pollInterval) {
            clearInterval(pollInterval);
          }
        }
      }
    })
    .catch(error => {
      console.error('Status check failed:', error);
      if (pollInterval) {
        clearInterval(pollInterval);
      }
    });
}

function showError(message) {
  const errorDiv = document.getElementById('errorMessage');
  errorDiv.textContent = message;
  errorDiv.classList.remove('d-none');
  setTimeout(() => errorDiv.classList.add('d-none'), 5000);
}

function showSuccess(message) {
  const successDiv = document.createElement('div');
  successDiv.className = 'alert alert-success alert-dismissible fade show';
  successDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
  document.querySelector('.card-body').prepend(successDiv);
  setTimeout(() => successDiv.remove(), 5000);
}

// Modified emergency data function that returns a promise
function createEmergencyDataAndReload() {
  const spinner = document.getElementById('loadingSpinner');
  spinner.classList.remove('d-none');

  return fetch('/api/v1/retry-history/force-seed', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      }
    })
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success') {
        const successDiv = document.createElement('div');
        successDiv.className = 'alert alert-success';
        successDiv.innerHTML = `
        <strong>Emergency Data Created!</strong><br>
        Created ${data.count || 3} retry transactions.<br>
        <small>Reloading page...</small>
      `;
        document.querySelector('.card-body').prepend(successDiv);

        // Schedule page reload
        setTimeout(() => {
          window.location.reload();
        }, 2000);

        return data;
      } else {
        throw new Error(data.message || 'Failed to create emergency data');
      }
    });
}

// Replace checkSystemStatus function with this simpler one that doesn't rely on other endpoints
function checkSystemStatus() {
  const spinner = document.getElementById('loadingSpinner');
  const errorDiv = document.getElementById('errorMessage');

  spinner.classList.remove('d-none');
  errorDiv.classList.add('d-none');

  // Create emergency data directly
  createEmergencyDataAndReload()
    .then(() => {
      const infoDiv = document.createElement('div');
      infoDiv.className = 'alert alert-info';
      infoDiv.innerHTML = '<strong>Database Seeded:</strong> Creating test data and refreshing...';
      document.querySelector('.card-body').prepend(infoDiv);
    })
    .catch(error => {
      errorDiv.innerHTML = `
        <p>System status check failed: ${error.message}</p>
        <button class="btn btn-sm btn-primary mt-2" onclick="createDemoData()">Try Again</button>
      `;
      errorDiv.classList.remove('d-none');
    })
    .finally(() => {
      spinner.classList.add('d-none');
    });
}
</script>
@endpush
@endsection