@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="row justify-content-center">
    <div class="col-md-12">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5>Create Test Transaction</h5>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-success" id="btnShowTemplates">Load Templates</button>
            <button class="btn btn-sm btn-outline-primary" id="btnBulkGenerate">
              <i class="fas fa-layer-group me-1"></i>Bulk Generate
            </button>
          </div>
        </div>
        <div class="card-body">
          @if (session('success'))
          <div class="alert alert-success">
            {{ session('success') }}
          </div>
          @endif

          @if (session('error'))
          <div class="alert alert-danger">
            {{ session('error') }}
          </div>
          @endif

          @if ($errors->any())
          <div class="alert alert-danger">
            <ul class="mb-0">
              @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
          @endif

          <!-- Transaction Templates Modal -->
          <div class="modal fade" id="templateModal" tabindex="-1" aria-labelledby="templateModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="templateModalLabel">Transaction Templates</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <div class="list-group">
                    <button type="button" class="list-group-item list-group-item-action"
                      onclick="loadTemplate('valid')">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-1">Valid Transaction</h6>
                        <span class="badge bg-success">VALID</span>
                      </div>
                      <p class="mb-1">Standard transaction with correct VAT calculation (12%)</p>
                    </button>

                    <button type="button" class="list-group-item list-group-item-action"
                      onclick="loadTemplate('invalidVat')">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-1">Invalid VAT Amount</h6>
                        <span class="badge bg-danger">INVALID</span>
                      </div>
                      <p class="mb-1">Transaction with incorrect VAT amount (too high)</p>
                    </button>

                    <button type="button" class="list-group-item list-group-item-action"
                      onclick="loadTemplate('invalidNetSales')">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-1">Invalid Net Sales</h6>
                        <span class="badge bg-danger">INVALID</span>
                      </div>
                      <p class="mb-1">Transaction with incorrect net sales calculation</p>
                    </button>

                    <button type="button" class="list-group-item list-group-item-action"
                      onclick="loadTemplate('largeAmount')">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-1">Large Transaction</h6>
                        <span class="badge bg-warning">MAY FAIL</span>
                      </div>
                      <p class="mb-1">Transaction with very large amount (may trigger amount limits)</p>
                    </button>

                    <button type="button" class="list-group-item list-group-item-action"
                      onclick="loadTemplate('zeroAmount')">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-1">Zero Amount</h6>
                        <span class="badge bg-danger">INVALID</span>
                      </div>
                      <p class="mb-1">Transaction with zero amount (should fail validation)</p>
                    </button>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>

          <form action="{{ route('transactions.test.process') }}" method="POST">
            @csrf

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="terminal_id" class="form-label">Terminal</label>
                <select name="terminal_id" id="terminal_id" class="form-select" required>
                  <option value="">Select Terminal</option>
                  @foreach($terminals as $terminal)
                  <option value="{{ $terminal->id }}" {{ old('terminal_id') == $terminal->id ? 'selected' : '' }}>
                    {{ $terminal->terminal_uid }} ({{ $terminal->tenant->name ?? 'Unknown' }})
                  </option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-6">
                <label for="transaction_id" class="form-label">Transaction ID</label>
                <div class="input-group">
                  <input type="text" name="transaction_id" id="transaction_id" class="form-control"
                    value="{{ old('transaction_id') }}" placeholder="Leave blank to auto-generate">
                  <button type="button" class="btn btn-outline-secondary"
                    onclick="generateTransactionId()">Generate</button>
                  <button type="button" class="btn btn-outline-info" onclick="checkTransactionIdExists()"><i
                      class="fas fa-check"></i> Check ID</button>
                </div>
                <small class="form-text text-muted">Format: TEST-YYYYMMDD-RANDOM or custom format</small>
                <div id="transaction-id-feedback" class="invalid-feedback"></div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="gross_sales" class="form-label">Gross Sales</label>
                <input type="number" name="gross_sales" id="gross_sales" class="form-control" step="0.01" min="0"
                  value="{{ old('gross_sales', '1120.00') }}" required>
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" id="auto_calculate" name="auto_calculate" checked>
                  <label class="form-check-label" for="auto_calculate">
                    Auto-calculate other fields
                  </label>
                </div>
              </div>

              <div class="col-md-6">
                <label for="net_sales" class="form-label">Net Sales</label>
                <input type="number" name="net_sales" id="net_sales" class="form-control" step="0.01" min="0"
                  value="{{ old('net_sales', '1000.00') }}" required>
                <div class="form-check form-check-inline mt-1">
                  <input class="form-check-input" type="radio" name="calculation_type" id="vat_inclusive"
                    value="inclusive" checked>
                  <label class="form-check-label" for="vat_inclusive">VAT Inclusive</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="calculation_type" id="vat_exclusive"
                    value="exclusive">
                  <label class="form-check-label" for="vat_exclusive">VAT Exclusive</label>
                </div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="vatable_sales" class="form-label">Vatable Sales</label>
                <input type="number" name="vatable_sales" id="vatable_sales" class="form-control" step="0.01" min="0"
                  value="{{ old('vatable_sales', '1000.00') }}" required>
              </div>

              <div class="col-md-6">
                <label for="vat_amount" class="form-label">VAT Amount</label>
              <div class="col-md-6">
                <label for="customer_code" class="form-label">Customer Code</label>
                <input type="text" name="customer_code" id="customer_code" class="form-control" value="{{ old('customer_code') }}" placeholder="Optional">
              </div>
                <div class="input-group">
                  <input type="number" name="vat_amount" id="vat_amount" class="form-control" step="0.01" min="0"
                    value="{{ old('vat_amount', '120.00') }}" required>
                  <span class="input-group-text" id="vat_percentage">12%</span>
                </div>
                <div class="progress mt-2" style="height: 5px;">
                  <div id="vatProgressBar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-4">
                <label for="transaction_count" class="form-label">Transaction Count</label>
                <input type="number" name="transaction_count" id="transaction_count" class="form-control" min="1"
                  value="{{ old('transaction_count', '1') }}" required>
              <div class="col-md-6">
                <label for="hardware_id" class="form-label">Hardware ID</label>
                <input type="text" name="hardware_id" id="hardware_id" class="form-control" value="{{ old('hardware_id') }}" placeholder="Optional">
              </div>
                <small class="form-text text-muted">
                  This will create the specified number of transactions (e.g., 3 will create 3 transactions).
                </small>
              </div>

              <div class="col-md-4">
                <label for="transaction_timestamp" class="form-label">Transaction Timestamp</label>
                <input type="datetime-local" name="transaction_timestamp" id="transaction_timestamp"
                  class="form-control" value="{{ old('transaction_timestamp', now()->format('Y-m-d\TH:i')) }}">
              </div>

              <div class="col-md-4">
                <label for="validation_override" class="form-label">Validation Override</label>
                <select name="validation_override" id="validation_override" class="form-select">
              <div class="col-md-6">
                <label for="base_amount" class="form-label">Base Amount</label>
                <input type="number" name="base_amount" id="base_amount" class="form-control" step="0.01" min="0" value="{{ old('base_amount', '1000.00') }}" required>
                <small class="form-text text-muted">Base amount before VAT and other calculations</small>
              </div>
                  <option value="">Normal Validation</option>
                  <option value="force_valid">Force Valid</option>
                  <option value="force_invalid">Force Invalid</option>
                </select>
              </div>
            </div>

            <div class="mb-3">
              <div id="validation-preview" class="alert alert-info d-none">
                <h6>Validation Preview:</h6>
                <div id="validation-results"></div>
              </div>

              <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Create Test Transaction</button>
                <button type="button" class="btn btn-success" onclick="previewValidation()">Preview Validation</button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset Form</button>
                <button type="button" class="btn btn-outline-secondary" onclick="createRandomData()">Random
                  Data</button>

                <div class="dropdown d-inline-block">
                  <button class="btn btn-warning dropdown-toggle" type="button" id="invalidDropdown"
                    data-bs-toggle="dropdown">
                    Create Invalid
                  </button>
                  <ul class="dropdown-menu" aria-labelledby="invalidDropdown">
                    <li><a class="dropdown-item" href="#" onclick="populateWithInvalidVAT()">Invalid VAT</a></li>
                    <li><a class="dropdown-item" href="#" onclick="populateWithInvalidNet()">Invalid Net Sales</a></li>
                    <li><a class="dropdown-item" href="#" onclick="populateWithZeroAmount()">Zero Amount</a></li>
                    <li><a class="dropdown-item" href="#" onclick="populateWithLargeAmount()">Large Amount</a></li>
                    <li><a class="dropdown-item" href="#" onclick="populateWithPastDate()">Past Date (30+ days)</a></li>
                  </ul>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Recent test transactions -->
      <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5>Recent Test Transactions</h5>
          <button class="btn btn-sm btn-outline-primary" onclick="refreshTransactionList()">
            <i class="fas fa-sync"></i> Refresh
          </button>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Terminal</th>
                  <th>Gross Sales</th>
                  <th>Status</th>
                  <th>Validation</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="recentTransactions">
                <tr>
                  <td colspan="7" class="text-center">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <span class="ms-2">Loading recent transactions...</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Generation Modal -->
<div class="modal fade" id="bulkGenerationModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Bulk Transaction Generator</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Number of Transactions</label>
          <input type="number" class="form-control" id="bulkCount" value="5" min="1" max="50">
          <small class="text-muted">Maximum 50 transactions per batch</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Send Frequency</label>
          <select class="form-select" id="bulkFrequency">
            <option value="instant">All at once</option>
            <option value="2">Every 2 seconds</option>
            <option value="5">Every 5 seconds</option>
            <option value="10">Every 10 seconds</option>
            <option value="30">Every 30 seconds</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount Variation</label>
          <select class="form-select" id="amountVariation">
            <option value="none">Use exact amounts</option>
            <option value="small">Small variations (±10%)</option>
            <option value="medium">Medium variations (±25%)</option>
            <option value="large">Large variations (±50%)</option>
          </select>
        </div>
        <div class="progress d-none" id="bulkProgress">
          <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"></div>
        </div>
        <div id="bulkStatus" class="mt-2 small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="startBulkGeneration()">Generate</button>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// Function to calculate values
function recalculateValues() {
  if (!document.getElementById('auto_calculate').checked) {
    return;
  }

  const grossSales = parseFloat(document.getElementById('gross_sales').value) || 0;
  const isVatInclusive = document.getElementById('vat_inclusive').checked;

  if (isVatInclusive) {
    // VAT Inclusive calculation (12/112 of gross)
    const vat = grossSales * 0.12 / 1.12;
    const netSales = grossSales - vat;

    document.getElementById('net_sales').value = netSales.toFixed(2);
    document.getElementById('vatable_sales').value = netSales.toFixed(2);
    document.getElementById('vat_amount').value = vat.toFixed(2);
  } else {
    // VAT Exclusive calculation (net + 12% of net)
    const netSales = grossSales;
    const vat = netSales * 0.12;
    const grossWithVat = netSales + vat;

    document.getElementById('gross_sales').value = grossWithVat.toFixed(2);
    document.getElementById('net_sales').value = netSales.toFixed(2);
    document.getElementById('vatable_sales').value = netSales.toFixed(2);
    document.getElementById('vat_amount').value = vat.toFixed(2);
  }

  updateVATValidationIndicator();
}

// Update VAT validation indicator
function updateVATValidationIndicator() {
  const vatable = parseFloat(document.getElementById('vatable_sales').value) || 0;
  const vat = parseFloat(document.getElementById('vat_amount').value) || 0;
  const expectedVat = vatable * 0.12;
  const difference = Math.abs(expectedVat - vat);
  const percentage = difference / expectedVat * 100;

  const progressBar = document.getElementById('vatProgressBar');

  // Update the progress bar
  if (percentage <= 0.5) {
    // Valid - very close
    progressBar.style.width = '100%';
    progressBar.className = 'progress-bar bg-success';
  } else if (percentage <= 2) {
    // Warning - within allowable range but not ideal
    progressBar.style.width = '75%';
    progressBar.className = 'progress-bar bg-warning';
  } else {
    // Invalid - too far off
    progressBar.style.width = '100%';
    progressBar.className = 'progress-bar bg-danger';
  }
}

// Event listeners for form inputs
document.getElementById('gross_sales').addEventListener('input', recalculateValues);
document.getElementById('net_sales').addEventListener('input', function() {
  if (document.getElementById('vat_exclusive').checked) {
    recalculateValues();
  }
});

document.getElementById('vatable_sales').addEventListener('input', updateVATValidationIndicator);
document.getElementById('vat_amount').addEventListener('input', updateVATValidationIndicator);

document.getElementById('auto_calculate').addEventListener('change', function() {
  const netSalesInput = document.getElementById('net_sales');
  const vatableSalesInput = document.getElementById('vatable_sales');
  const vatAmountInput = document.getElementById('vat_amount');

  if (this.checked) {
    recalculateValues();
  }
});

document.getElementById('vat_inclusive').addEventListener('change', recalculateValues);
document.getElementById('vat_exclusive').addEventListener('change', recalculateValues);

// Template management
document.getElementById('btnShowTemplates').addEventListener('click', function() {
  const templateModal = new bootstrap.Modal(document.getElementById('templateModal'));
  templateModal.show();
});

function loadTemplate(type) {
  switch (type) {
    case 'valid':
      document.getElementById('gross_sales').value = '1120.00';
      document.getElementById('net_sales').value = '1000.00';
      document.getElementById('vatable_sales').value = '1000.00';
      document.getElementById('vat_amount').value = '120.00';
      document.getElementById('vat_inclusive').checked = true;
      document.getElementById('auto_calculate').checked = true;
      break;

    case 'invalidVat':
      document.getElementById('gross_sales').value = '1120.00';
      document.getElementById('net_sales').value = '1000.00';
      document.getElementById('vatable_sales').value = '1000.00';
      document.getElementById('vat_amount').value = '200.00'; // Invalid VAT (should be 120)
      document.getElementById('auto_calculate').checked = false;
      break;

    case 'invalidNetSales':
      document.getElementById('gross_sales').value = '1120.00';
      document.getElementById('net_sales').value = '900.00'; // Invalid net (should be 1000)
      document.getElementById('vatable_sales').value = '900.00';
      document.getElementById('vat_amount').value = '120.00';
      document.getElementById('auto_calculate').checked = false;
      break;

    case 'largeAmount':
      document.getElementById('gross_sales').value = '500000.00';
      recalculateValues();
      break;

    case 'zeroAmount':
      document.getElementById('gross_sales').value = '0.00';
      document.getElementById('net_sales').value = '0.00';
      document.getElementById('vatable_sales').value = '0.00';
      document.getElementById('vat_amount').value = '0.00';
      document.getElementById('auto_calculate').checked = false;
      break;
  }

  updateVATValidationIndicator();
  bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
}

// Generate Transaction ID
function generateTransactionId() {
  const now = new Date();
  const dateStr = now.getFullYear() +
    ('0' + (now.getMonth() + 1)).slice(-2) +
    ('0' + now.getDate()).slice(-2);
  const randomStr = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
  document.getElementById('transaction_id').value = `TEST-${dateStr}-${randomStr}`;
}

// Add a new function to check if transaction ID exists
function checkTransactionIdExists() {
  const transactionId = document.getElementById('transaction_id').value.trim();
  const feedbackElement = document.getElementById('transaction-id-feedback');
  const inputElement = document.getElementById('transaction_id');

  if (!transactionId) {
    feedbackElement.textContent = 'Please enter a transaction ID first';
    feedbackElement.style.display = 'block';
    inputElement.classList.add('is-invalid');
    return;
  }

  // Show checking indicator
  inputElement.classList.remove('is-invalid', 'is-valid');
  feedbackElement.textContent = 'Checking...';
  feedbackElement.style.display = 'block';

  fetch(`/api/v1/transaction-id-exists?id=${encodeURIComponent(transactionId)}`)
    .then(response => response.json())
    .then(data => {
      if (data.exists) {
        inputElement.classList.add('is-invalid');
        feedbackElement.textContent = 'This transaction ID already exists. Please use a different one.';
        feedbackElement.style.display = 'block';
      } else {
        inputElement.classList.add('is-valid');
        inputElement.classList.remove('is-invalid');
        feedbackElement.textContent = 'Transaction ID is available.';
        feedbackElement.className = 'valid-feedback';
        feedbackElement.style.display = 'block';

        // Hide the feedback after 3 seconds
        setTimeout(() => {
          feedbackElement.style.display = 'none';
          inputElement.classList.remove('is-valid');
        }, 3000);
      }
    })
    .catch(error => {
      feedbackElement.textContent = 'Error checking transaction ID: ' + error.message;
      feedbackElement.style.display = 'block';
      inputElement.classList.add('is-invalid');
    });
}

// Add event listener for transaction ID input to clear validation when changed
document.getElementById('transaction_id').addEventListener('input', function() {
  this.classList.remove('is-invalid', 'is-valid');
  document.getElementById('transaction-id-feedback').style.display = 'none';
});

// Validation preview
function previewValidation() {
  const grossSales = parseFloat(document.getElementById('gross_sales').value) || 0;
  const netSales = parseFloat(document.getElementById('net_sales').value) || 0;
  const vatableSales = parseFloat(document.getElementById('vatable_sales').value) || 0;
  const vatAmount = parseFloat(document.getElementById('vat_amount').value) || 0;

  const results = [];
  let isValid = true;

  // Check gross sales
  if (grossSales <= 0) {
    results.push('<span class="text-danger">❌ Gross sales must be positive</span>');
    isValid = false;
  } else {
    results.push('<span class="text-success">✓ Gross sales is positive</span>');
  }

  // Check net sales
  if (netSales <= 0) {
    results.push('<span class="text-danger">❌ Net sales must be positive</span>');
    isValid = false;
  } else {
    results.push('<span class="text-success">✓ Net sales is positive</span>');
  }

  // Check VAT calculation
  const expectedVat = vatableSales * 0.12;
  const vatDiff = Math.abs(expectedVat - vatAmount);
  if (vatDiff > 0.02) {
    results.push(
      `<span class="text-danger">❌ VAT amount ${vatAmount.toFixed(2)} does not match expected ${expectedVat.toFixed(2)}</span>`
    );
    isValid = false;
  } else {
    results.push('<span class="text-success">✓ VAT calculation is correct</span>');
  }

  // Check net vs gross
  const expectedNet = grossSales - vatAmount;
  const netDiff = Math.abs(expectedNet - netSales);
  if (netDiff > 0.02) {
    results.push(
      `<span class="text-danger">❌ Net sales ${netSales.toFixed(2)} does not match gross minus VAT ${expectedNet.toFixed(2)}</span>`
    );
    isValid = false;
  } else {
    results.push('<span class="text-success">✓ Net sales calculation is correct</span>');
  }

  // Display results
  const validationPreview = document.getElementById('validation-preview');
  validationPreview.classList.remove('d-none', 'alert-success', 'alert-danger');
  validationPreview.classList.add(isValid ? 'alert-success' : 'alert-danger');

  document.getElementById('validation-results').innerHTML = results.join('<br>');
}

// Reset form
function resetForm() {
  document.getElementById('transaction_id').value = '';
  document.getElementById('gross_sales').value = '1120.00';
  document.getElementById('vat_inclusive').checked = true;
  document.getElementById('auto_calculate').checked = true;
  recalculateValues();

  document.getElementById('validation-preview').classList.add('d-none');
}

// Create random data
function createRandomData() {
  const grossSales = (Math.random() * 5000 + 100).toFixed(2);
  document.getElementById('gross_sales').value = grossSales;
  document.getElementById('vat_inclusive').checked = true;
  recalculateValues();
}

// Invalid data population functions
function populateWithInvalidVAT() {
  document.getElementById('gross_sales').value = '1120.00';
  document.getElementById('net_sales').value = '1000.00';
  document.getElementById('vatable_sales').value = '1000.00';
  document.getElementById('vat_amount').value = '200.00'; // Invalid VAT
  document.getElementById('auto_calculate').checked = false;
  updateVATValidationIndicator();
}

function populateWithInvalidNet() {
  document.getElementById('gross_sales').value = '1120.00';
  document.getElementById('net_sales').value = '900.00'; // Invalid net
  document.getElementById('vatable_sales').value = '1000.00';
  document.getElementById('vat_amount').value = '120.00';
  document.getElementById('auto_calculate').checked = false;
  updateVATValidationIndicator();
}

function populateWithZeroAmount() {
  document.getElementById('gross_sales').value = '0.00';
  document.getElementById('net_sales').value = '0.00';
  document.getElementById('vatable_sales').value = '0.00';
  document.getElementById('vat_amount').value = '0.00';
  document.getElementById('auto_calculate').checked = false;
  updateVATValidationIndicator();
}

function populateWithLargeAmount() {
  document.getElementById('gross_sales').value = '1000000.00';
  document.getElementById('vat_inclusive').checked = true;
  document.getElementById('auto_calculate').checked = true;
  recalculateValues();
}

function populateWithPastDate() {
  const date = new Date();
  date.setDate(date.getDate() - 40); // 40 days ago
  const formattedDate = date.toISOString().slice(0, 16);
  document.getElementById('transaction_timestamp').value = formattedDate;
}

// Refresh transaction list
function refreshTransactionList() {
  loadRecentTransactions();
}

// Load recent transactions
function loadRecentTransactions() {
  fetch('/api/v1/recent-test-transactions')
    .then(response => {
      if (!response.ok) {
        throw new Error(`Network response was not ok (${response.status})`);
      }
      return response.json();
    })
    .then(data => {
      const tbody = document.getElementById('recentTransactions');

      if (data.status === 'success' && data.data && data.data.length > 0) {
        let html = '';

        data.data.forEach(tx => {
          const statusClass = tx.job_status === 'COMPLETED' ? 'success' :
            tx.job_status === 'FAILED' ? 'danger' :
            tx.job_status === 'PROCESSING' ? 'primary' : 'secondary';

          const validationClass = tx.validation_status === 'VALID' ? 'success' :
            tx.validation_status === 'INVALID' ? 'danger' : 'info';

          html += `
            <tr>
              <td>${tx.transaction_id}</td>
              <td>${tx.terminal_uid}</td>
              <td>${parseFloat(tx.gross_sales).toFixed(2)}</td>
              <td><span class="badge bg-${statusClass}">${tx.job_status}</span></td>
              <td><span class="badge bg-${validationClass}">${tx.validation_status}</span></td>
              <td>${new Date(tx.created_at).toLocaleString()}</td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a href="/transactions/${tx.id}" class="btn btn-info">
                    <i class="fas fa-eye"></i>
                  </a>
                  <button type="button" class="btn btn-success" onclick="cloneTransaction(${tx.id})">
                    <i class="fas fa-copy"></i>
                  </button>
                  ${tx.job_status === 'FAILED' ? 
                    `<button type="button" class="btn btn-warning" onclick="retryTransaction(${tx.id})">
                      <i class="fas fa-redo"></i>
                    </button>` : ''
                  }
                </div>
              </td>
            </tr>
          `;
        });

        tbody.innerHTML = html;
      } else {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">No test transactions found</td></tr>';
      }
    })
    .catch(error => {
      console.error('Error loading recent transactions:', error);
      document.getElementById('recentTransactions').innerHTML =
        '<tr><td colspan="7" class="text-center text-danger">Error loading transactions: ' + error.message +
        '</td></tr>';
    });
}

// Clone transaction to form
function cloneTransaction(id) {
  fetch(`/api/v1/transactions/${id}/details`)
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success') {
        const tx = data.data;
        document.getElementById('terminal_id').value = tx.terminal_id;
        document.getElementById('gross_sales').value = tx.gross_sales;
        document.getElementById('net_sales').value = tx.net_sales;
        document.getElementById('vatable_sales').value = tx.vatable_sales;
        document.getElementById('vat_amount').value = tx.vat_amount;
        document.getElementById('transaction_count').value = tx.transaction_count;
        document.getElementById('auto_calculate').checked = false;

        // Generate a new ID based on the old one
        const oldId = tx.transaction_id;
        const newId = oldId.includes('CLONE') ?
          oldId :
          `CLONE-${oldId}-${Math.floor(Math.random() * 1000).toString().padStart(3, '0')}`;

        document.getElementById('transaction_id').value = newId;

        // Scroll to form
        document.querySelector('.card').scrollIntoView({
          behavior: 'smooth'
        });

        // Show success message
        const msg = document.createElement('div');
        msg.className = 'alert alert-success';
        msg.textContent = 'Transaction data loaded to form';
        document.querySelector('.card-body').prepend(msg);

        setTimeout(() => msg.remove(), 3000);
      }
    })
    .catch(error => {
      console.error('Error loading transaction details:', error);
      alert('Failed to load transaction details');
    });
}

// Retry transaction
function retryTransaction(id) {
  if (confirm('Are you sure you want to retry this transaction?')) {
    fetch(`/api/v1/retry-history/${id}/retry`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
      })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          alert('Transaction queued for retry');
          loadRecentTransactions();
        } else {
          alert('Failed to retry transaction: ' + data.message);
        }
      })
      .catch(error => {
        console.error('Error retrying transaction:', error);
        alert('Failed to retry transaction');
      });
  }
}

// Bulk transaction functions
function showBulkGenerator() {
  const modal = new bootstrap.Modal(document.getElementById('bulkGenerationModal'));
  modal.show();
}

function startBulkGeneration() {
  const count = parseInt(document.getElementById('bulkCount').value);
  const frequency = document.getElementById('bulkFrequency').value; // Get value as string or number
  const variation = document.getElementById('amountVariation').value;

  if (count < 1 || count > 50) {
    alert('Please enter a valid number of transactions (1-50)');
    return;
  }

  const progress = document.getElementById('bulkProgress');
  const progressBar = progress.querySelector('.progress-bar');
  const status = document.getElementById('bulkStatus');

  progress.classList.remove('d-none');
  progressBar.style.width = '0%';

  // Get base transaction data from form
  const baseData = {
    terminal_id: document.getElementById('terminal_id').value,
    gross_sales: parseFloat(document.getElementById('gross_sales').value) || 0,
    net_sales: parseFloat(document.getElementById('net_sales').value) || 0,
    vatable_sales: parseFloat(document.getElementById('vatable_sales').value) || 0,
    vat_amount: parseFloat(document.getElementById('vat_amount').value) || 0,
    transaction_count: parseInt(document.getElementById('transaction_count').value) || 1,
    transaction_timestamp: document.getElementById('transaction_timestamp').value || new Date().toISOString().slice(0,
      16)
  };

  // Validate base data
  if (!baseData.terminal_id) {
    alert('Please select a terminal first');
    return;
  }

  let completed = 0;
  let failed = 0;
  let delayInterval = frequency === 'instant' ? 0 : parseInt(frequency) * 1000;

  function sendTransaction(index) {
    if (index >= count) {
      status.innerHTML = `Completed: ${completed} successful, ${failed} failed`;
      // Refresh the transactions list after completion
      loadRecentTransactions();
      return;
    }

    // Create transaction data with variations if selected
    const data = {
      ...baseData
    };

    // Apply amount variation
    if (variation !== 'none') {
      const variationPercent =
        variation === 'small' ? 0.1 :
        variation === 'medium' ? 0.25 : 0.5;

      const randomFactor = 1 + (Math.random() * variationPercent * 2 - variationPercent);

      data.gross_sales = +(data.gross_sales * randomFactor).toFixed(2);
      data.net_sales = +(data.net_sales * randomFactor).toFixed(2);
      data.vatable_sales = +(data.vatable_sales * randomFactor).toFixed(2);
      data.vat_amount = +(data.vat_amount * randomFactor).toFixed(2);
    }

    // Generate unique transaction ID
    data.transaction_id = `BULK-${Date.now()}-${index + 1}`;

    // Create form data for submission
    const formData = new FormData();
    Object.keys(data).forEach(key => {
      formData.append(key, data[key]);
    });

    // Add CSRF token
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    // Submit the form
    fetch('/transactions/test/process', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest' // Mark as AJAX request
        }
      })
      .then(response => {
        if (!response.ok) throw new Error(`HTTP error ${response.status}`);
        return response.json();
      })
      .then(data => {
        // Handle both new transactions and already existing ones as successes
        completed++;

        // Add note if it was a duplicate
        if (data.data && data.data.already_exists) {
          console.log(`Transaction ${data.data.transaction_id} already exists, counted as success`);
        }

        updateProgress();

        if (index < count - 1) {
          setTimeout(() => sendTransaction(index + 1), delayInterval);
        } else {
          // All done - show completion message
          progressBar.classList.remove('progress-bar-animated');
          status.innerHTML = `✅ All done! ${completed} transactions created successfully, ${failed} failed.`;
          loadRecentTransactions();
        }
      })
      .catch(error => {
        console.error('Transaction failed:', error);
        failed++;
        updateProgress();

        if (index < count - 1) {
          setTimeout(() => sendTransaction(index + 1), delayInterval);
        } else {
          // All done - show completion message with failures
          progressBar.classList.remove('progress-bar-animated');
          status.innerHTML = `⚠️ Process complete with errors: ${completed} successful, ${failed} failed.`;
          loadRecentTransactions();
        }
      });
  }

  function updateProgress() {
    const percent = ((completed + failed) / count * 100).toFixed(1);
    progressBar.style.width = `${percent}%`;
    progressBar.textContent = `${percent}%`;
    status.innerHTML = `Progress: ${completed} successful, ${failed} failed, ${count - (completed + failed)} remaining`;
  }

  // Start sending transactions
  status.innerHTML = `Starting bulk generation of ${count} transactions...`;
  sendTransaction(0);
}

// Add event listener for bulk generate button
document.getElementById('btnBulkGenerate').addEventListener('click', showBulkGenerator);

// Load recent transactions on page load
document.addEventListener('DOMContentLoaded', loadRecentTransactions);
</script>
@endpush
@endsection