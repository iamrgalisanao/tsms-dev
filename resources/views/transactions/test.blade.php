@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Test Transaction Processing</h5>
      <div>
        <button type="button" class="btn btn-secondary me-2" id="validateJson">Validate JSON</button>
        <button type="button" class="btn btn-secondary" id="generateJson">Generate Random</button>
      </div>
    </div>
    <div class="card-body">
      <form id="testTransactionForm" class="needs-validation" novalidate>
        @csrf
        <div class="mb-3">
          <label class="form-label">Terminal</label>
          <select class="form-select" name="terminal_id" required>
            <option value="">Select Terminal</option>
            @foreach($terminals as $terminal)
            <option value="{{ $terminal->id }}" data-provider="{{ $terminal->provider->code }}"
              data-tenant="{{ $terminal->tenant_id }}">
              {{ $terminal->provider->name }} - {{ $terminal->terminal_uid }}
            </option>
            @endforeach
          </select>
          <div class="invalid-feedback">Please select a terminal</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Transaction Payload (Editable JSON)</label>
          <textarea class="form-control font-monospace" id="payloadJson" name="payload" rows="20" required
            spellcheck="false"></textarea>
          <div class="invalid-feedback">Please provide valid JSON payload</div>
        </div>

        <button type="submit" class="btn btn-primary">Submit Transaction</button>
      </form>

      <div id="resultArea" class="mt-4" style="display: none;">
        <h6>Processing Result:</h6>
        <pre class="bg-light p-3 rounded"><code id="resultJson"></code></pre>

        <!-- Add Transaction Monitoring Panel -->
        <div id="monitoringPanel" class="mt-4">
          <h6>Transaction Status:</h6>
          <div class="card">
            <div class="card-body">
              <div class="row">
                <div class="col-md-3">
                  <strong>Status:</strong>
                  <span id="txnStatus" class="badge bg-secondary">Waiting...</span>
                </div>
                <div class="col-md-3">
                  <strong>Validation:</strong>
                  <span id="txnValidation" class="badge bg-secondary">Pending</span>
                </div>
                <div class="col-md-3">
                  <strong>Attempts:</strong>
                  <span id="txnAttempts">0</span>
                </div>
                <div class="col-md-3">
                  <strong>Updated:</strong>
                  <span id="txnUpdated">-</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
function generateRandomTransaction(terminalData = null) {
  const timestamp = new Date().toISOString();
  const transactionId = 'TXN-' + Date.now() + '-' + Math.random().toString(36).substring(2, 6);

  const grossSales = parseFloat((Math.random() * 10000).toFixed(2));
  const vatAmount = parseFloat((grossSales * 0.12).toFixed(2));
  const netSales = parseFloat((grossSales - vatAmount).toFixed(2));

  return {
    "tenant_id": terminalData?.tenant || "T-" + Math.floor(Math.random() * 9999).toString().padStart(4, '0'),
    "hardware_id": terminalData?.id || "H" + Math.random().toString(36).substring(2, 8).toUpperCase(),
    "machine_number": Math.floor(Math.random() * 10) + 1,
    "transaction_id": transactionId,
    "store_name": "Test Store #" + Math.floor(Math.random() * 100),
    "transaction_timestamp": timestamp,
    "vatable_sales": grossSales,
    "net_sales": netSales,
    "vat_exempt_sales": 0,
    "promo_discount_amount": 0,
    "promo_status": "NO_PROMO",
    "discount_total": 0,
    "discount_details": {},
    "other_tax": 0,
    "management_service_charge": 0,
    "employee_service_charge": 0,
    "gross_sales": grossSales,
    "vat_amount": vatAmount,
    "transaction_count": 1,
    "validation_status": "PENDING",
    "error_code": ""
  };
}

// Add JSON validation
function validateJsonPayload(jsonString) {
  try {
    const payload = JSON.parse(jsonString);
    return {
      valid: true,
      payload
    };
  } catch (e) {
    return {
      valid: false,
      error: e.message
    };
  }
}

document.getElementById('validateJson').addEventListener('click', function() {
  const jsonString = document.getElementById('payloadJson').value;
  const result = validateJsonPayload(jsonString);

  if (result.valid) {
    alert('JSON is valid!');
  } else {
    alert('Invalid JSON: ' + result.error);
  }
});

// Update generate function to use terminal data
document.getElementById('generateJson').addEventListener('click', function() {
  const terminalSelect = document.querySelector('[name="terminal_id"]');
  const selectedOption = terminalSelect.selectedOptions[0];

  const terminalData = selectedOption.value ? {
    id: selectedOption.value,
    tenant: selectedOption.dataset.tenant,
    provider: selectedOption.dataset.provider
  } : null;

  const randomTransaction = generateRandomTransaction(terminalData);
  document.getElementById('payloadJson').value = JSON.stringify(randomTransaction, null, 2);
});

// Update form submission
document.getElementById('testTransactionForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const resultArea = document.getElementById('resultArea');
  const resultJson = document.getElementById('resultJson');
  resultArea.style.display = 'block';

  try {
    const terminalId = this.terminal_id.value;
    const jsonResult = validateJsonPayload(this.payload.value);

    if (!jsonResult.valid) {
      throw new Error('Invalid JSON payload: ' + jsonResult.error);
    }

    const data = {
      terminal_id: terminalId,
      transaction_id: jsonResult.payload.transaction_id,
      payload: jsonResult.payload
    };

    const response = await fetch('{{ url("api/transactions") }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': '{{ csrf_token() }}'
      },
      body: JSON.stringify(data)
    });

    const contentType = response.headers.get('content-type');

    if (!response.ok) {
      // Try to get error details from response
      let errorMessage = 'Server error occurred';
      if (contentType && contentType.includes('application/json')) {
        const errorData = await response.json();
        errorMessage = errorData.message || errorMessage;
      } else {
        const text = await response.text();
        errorMessage = `Server returned non-JSON response (${response.status}). Check server logs for details.`;
      }
      throw new Error(errorMessage);
    }

    if (!contentType || !contentType.includes('application/json')) {
      throw new Error('Server returned invalid content type. Expected JSON but got: ' + (contentType || 'none'));
    }

    const result = await response.json();
    resultJson.textContent = JSON.stringify(result, null, 2);

    if (result.status === 'success') {
      pollTransactionStatus(result.data.transaction_id);
    }
  } catch (error) {
    console.error('Error:', error);
    resultJson.textContent = JSON.stringify({
      status: 'error',
      message: error.message,
      timestamp: new Date().toISOString()
    }, null, 2);
  }
});

function updateMonitoringPanel(result) {
  const status = result.data.job_status;
  const validation = result.data.validation_status;

  // Update status badge
  const statusBadge = document.getElementById('txnStatus');
  statusBadge.textContent = status;
  statusBadge.className = 'badge ' + getStatusClass(status);

  // Update validation badge
  const validationBadge = document.getElementById('txnValidation');
  validationBadge.textContent = validation;
  validationBadge.className = 'badge ' + getValidationClass(validation);

  // Update other fields
  document.getElementById('txnAttempts').textContent = result.data.job_attempts;
  document.getElementById('txnUpdated').textContent = new Date().toLocaleTimeString();
}

function getStatusClass(status) {
  switch (status) {
    case 'COMPLETED':
      return 'bg-success';
    case 'FAILED':
      return 'bg-danger';
    case 'PROCESSING':
      return 'bg-primary';
    default:
      return 'bg-secondary';
  }
}

function getValidationClass(status) {
  switch (status) {
    case 'VALID':
      return 'bg-success';
    case 'INVALID':
      return 'bg-danger';
    default:
      return 'bg-secondary';
  }
}

function pollTransactionStatus(transactionId) {
  const interval = setInterval(async () => {
    try {
      const response = await fetch('{{ url("api/transactions") }}/' + transactionId + '/status');
      const result = await response.json();

      document.getElementById('resultJson').textContent = JSON.stringify(result, null, 2);
      updateMonitoringPanel(result);

      if (result.data.job_status === 'COMPLETED' || result.data.job_status === 'FAILED') {
        clearInterval(interval);
      }
    } catch (error) {
      console.error('Error polling status:', error);
      clearInterval(interval);
    }
  }, 2000);
}
</script>
@endpush
@endsection