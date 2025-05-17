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
          <a href="/transactions">Transactions</a>
          <a href="/circuit-breakers">Circuit Breakers</a>
          <a href="/terminal-tokens">Terminal Tokens</a>
          <a href="/dashboard/retry-history">Retry History</a>
          <a href="/logs">Log Viewer</a>
          <a href="/providers">POS Providers</a>
        </div>
        <div class="col-md-9 col-lg-10 main-content">
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
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script>
  // Pass auth data to JavaScript
  window.authUser = @json(Auth::user());
  window.csrfToken = "{{ csrf_token() }}";
  </script>

  @if(file_exists(public_path('js/app.js')))
  <script src="{{ asset('js/app.js') }}"></script>
  @else
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  console.log('React app assets not found. Using fallback layout.');
  // Basic JavaScript to handle sidebar navigation
  document.addEventListener('DOMContentLoaded', function() {
    const currentPath = window.location.pathname;
    const sidebarLinks = document.querySelectorAll('.sidebar a');
    sidebarLinks.forEach(link => {
      if (currentPath.includes(link.getAttribute('href'))) {
        link.style.backgroundColor = 'rgba(255,255,255,.2)';
        link.style.color = '#fff';
      }
    });
  });
  </script>
  @endif
</body>

</html>