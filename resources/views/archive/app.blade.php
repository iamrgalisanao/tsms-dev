<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>TSMS Dashboard</title>
  <!-- Check for compiled assets or use CDN fallbacks -->
  @if(file_exists(public_path('css/app.css')))
  <link href="{{ asset('css/app.css') }}" rel="stylesheet">
  @else
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Add other necessary CSS libraries your app might use -->
  @endif
  <style>
  /* Basic styles to make the dashboard presentable */
  body {
    background-color: #f8f9fa;
  }

  .navbar {
    margin-bottom: 20px;
  }

  .card {
    margin-bottom: 20px;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
  }

  .sidebar {
    min-height: calc(100vh - 56px);
    background-color: #343a40;
    padding: 20px 0;
  }

  .sidebar a {
    color: rgba(255, 255, 255, .75);
    padding: 10px 20px;
    display: block;
  }

  .sidebar a:hover {
    color: #fff;
    background-color: rgba(255, 255, 255, .1);
    text-decoration: none;
  }

  .main-content {
    padding: 20px;
  }
  </style>
</head>

<body>
  <div id="app">
    <!-- Fallback content until React loads -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
      <div class="container-fluid">
        <a class="navbar-brand" href="#">TSMS Dashboard</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav ms-auto">
            <li class="nav-item">
              <span class="nav-link">Welcome, {{ Auth::user()->name }}</span>
            </li>
            <li class="nav-item">
              <form action="{{ route('logout') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-link nav-link">Logout</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container-fluid">
      <div class="row">
        <div class="col-md-3 col-lg-2 sidebar">
          <a href="/dashboard">Dashboard</a>
          <a href="{{ route('transactions') }}">Transactions</a> <!-- Keep only this single transactions link -->
          <a href="/circuit-breakers">Circuit Breakers</a>
          <a href="/terminal-tokens">Terminal Tokens</a>
          <a href="/dashboard/retry-history">Retry History</a>
          <a href="/logs">Log Viewer</a>
          <a href="/providers">POS Providers</a>
          <a href="/terminal-test">Terminal Testing</a>
        </div>
        <div class="col-md-9 col-lg-10 main-content">
          <!-- Conditional display based on route -->
          @if(request()->is('terminal-test'))
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5>Terminal Testing</h5>
              <button id="clearResults" class="btn btn-sm btn-outline-secondary">Clear Results</button>
            </div>
            <div class="card-body">
              <div class="row mb-4">
                <div class="col-md-6">
                  <h6>Test Transaction Input</h6>
                  <div class="form-group">
                    <label for="formatSelect">Select Format:</label>
                    <select id="formatSelect" class="form-select mb-2">
                      <option value="keyColon">KEY: VALUE</option>
                      <option value="keyEquals">KEY=VALUE</option>
                      <option value="keySpace">KEY VALUE</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="textInput">Transaction Data:</label>
                    <textarea id="textInput" class="form-control" rows="10"
                      placeholder="Enter transaction data..."></textarea>
                  </div>
                  <button id="testButton" class="btn btn-primary mt-3">Test Parse</button>
                </div>
                <div class="col-md-6">
                  <h6>Test Results</h6>
                  <div id="testResults" class="border p-3 bg-light"
                    style="min-height: 300px; max-height: 400px; overflow-y: auto;">
                    <div class="text-muted">Results will appear here...</div>
                  </div>
                </div>
              </div>
              <div class="row">
                <div class="col-12">
                  <h6>Sample Data</h6>
                  <div class="accordion" id="sampleDataAccordion">
                    <div class="accordion-item">
                      <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                          data-bs-target="#collapseOne">
                          KEY: VALUE Format
                        </button>
                      </h2>
                      <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#sampleDataAccordion">
                        <div class="accordion-body">
                          <pre>TENANT_ID: ABC123
TRANSACTION_ID: TX789
TRANSACTION_TIMESTAMP: 2023-10-15 14:30:00
GROSS_SALES: 1500.75
NET_SALES: 1340.25
VATABLE_SALES: 1200.00
VAT_EXEMPT_SALES: 140.25
VAT_AMOUNT: 144.00
TRANSACTION_COUNT: 5
PAYLOAD_CHECKSUM: a1b2c3d4e5f6g7h8i9j0</pre>
                          <button class="btn btn-sm btn-outline-secondary copy-sample">Copy</button>
                        </div>
                      </div>
                    </div>
                    <!-- Add more accordion items for other formats -->
                  </div>
                </div>
              </div>
            </div>
          </div>
          @else
          <div class="card">
            <div class="card-header">
              <h5>Dashboard</h5>
            </div>
            <div class="card-body">
              <p>Welcome to the TSMS Dashboard!</p>
              <p>You are now logged in as {{ Auth::user()->email }}.</p>
              <div class="alert alert-info">
                Note: The dashboard is loading without React components. Check browser console for errors.
              </div>
            </div>
          </div>
          @endif
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script>
  // Pass auth data to JavaScript
  window.authUser = @json(Auth::user());
  window.csrfToken = "{{ csrf_token() }}";

  // Terminal Testing JavaScript
  document.addEventListener('DOMContentLoaded', function() {
    // Existing sidebar highlight code
    const currentPath = window.location.pathname;
    const sidebarLinks = document.querySelectorAll('.sidebar a');
    sidebarLinks.forEach(link => {
      if (currentPath.includes(link.getAttribute('href'))) {
        link.style.backgroundColor = 'rgba(255,255,255,.2)';
        link.style.color = '#fff';
      }
    });

    // Terminal testing functionality
    const testButton = document.getElementById('testButton');
    const clearButton = document.getElementById('clearResults');
    const formatSelect = document.getElementById('formatSelect');
    const textInput = document.getElementById('textInput');
    const testResults = document.getElementById('testResults');
    const copySampleButtons = document.querySelectorAll('.copy-sample');

    if (testButton) {
      testButton.addEventListener('click', function() {
        testResults.innerHTML =
          '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';

        fetch('/api/test/transactions', {
            method: 'POST',
            headers: {
              'Content-Type': 'text/plain',
              'X-CSRF-TOKEN': window.csrfToken
            },
            body: textInput.value
          })
          .then(response => response.json())
          .then(data => {
            testResults.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
          })
          .catch(error => {
            testResults.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
          });
      });
    }

    if (clearButton) {
      clearButton.addEventListener('click', function() {
        testResults.innerHTML = '<div class="text-muted">Results will appear here...</div>';
      });
    }

    // Copy sample data to input
    copySampleButtons.forEach(button => {
      button.addEventListener('click', function() {
        const sampleText = this.previousElementSibling.innerText;
        textInput.value = sampleText;
      });
    });
  });
  </script>

  @if(file_exists(public_path('js/app.js')))
  <script src="{{ asset('js/app.js') }}"></script>
  @else
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  console.log('React app assets not found. Using fallback layout.');
  </script>
  @endif
</body>

</html>