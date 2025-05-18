<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name', 'TSMS') }}</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Custom CSS -->
  <style>
  body {
    background-color: #f8f9fa;
  }

  .navbar {
    margin-bottom: 20px;
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

  .sidebar a.active {
    color: #fff;
    background-color: rgba(255, 255, 255, .2);
  }

  .main-content {
    padding: 20px;
  }
  </style>

  @yield('styles')
</head>

<body>
  <!-- Navigation -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
      <a class="navbar-brand" href="{{ route('dashboard') }}">TSMS Dashboard</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a>
          </li>
          @if (Auth::check())
          <li class="nav-item">
            <span class="nav-link">Welcome, {{ Auth::user()->name }}</span>
          </li>
          <li class="nav-item">
            <form action="{{ route('logout') }}" method="POST" class="d-inline">
              @csrf
              <button type="submit" class="btn btn-link nav-link">Logout</button>
            </form>
          </li>
          @else
          <li class="nav-item">
            <a href="{{ route('login') }}" class="nav-link">Login</a>
          </li>
          @endif
        </ul>
      </div>
    </div>
  </nav>

  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar -->
      <div class="col-md-3 col-lg-2 sidebar">
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('transactions') }}"
          class="{{ request()->routeIs('transactions') ? 'active' : '' }}">Transactions</a>
        <a href="{{ route('circuit-breakers') }}"
          class="{{ request()->routeIs('circuit-breakers') ? 'active' : '' }}">Circuit Breakers</a>
        <a href="{{ route('terminal-tokens') }}"
          class="{{ request()->routeIs('terminal-tokens') ? 'active' : '' }}">Terminal Tokens</a>
        <a href="{{ route('dashboard.retry-history') }}"
          class="{{ request()->routeIs('dashboard.retry-history') ? 'active' : '' }}">Retry History</a>
        <a href="{{ route('log-viewer') }}" 
          class="{{ request()->routeIs('log-viewer', 'log-viewer.*') ? 'active' : '' }}">Log Viewer</a>
        <!-- Removed POS Providers link as it's now integrated into the dashboard -->
      </div>

      <!-- Main Content -->
      <div class="col-md-9 col-lg-10 main-content">
        @yield('content')
      </div>
    </div>
  </div>

  <!-- Bootstrap Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Additional Scripts -->
  @yield('scripts')
</body>

</html>