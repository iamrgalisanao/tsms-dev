<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name', 'TSMS') }}</title>

  <!-- Styles -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
  .wrapper {
    display: flex;
    min-height: 100vh;
  }

  .main-sidebar {
    width: 250px;
    background: #343a40;
    padding-top: 1rem;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
  }

  .brand {
    color: #fff;
    font-size: 1.5rem;
    padding: 0 1rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, .1);
    margin-bottom: 1rem;
  }

  .main-content {
    flex: 1;
    margin-left: 250px;
    padding: 1rem;
  }

  .nav-link {
    color: rgba(255, 255, 255, .75);
    padding: .5rem 1rem;
  }

  .nav-link:hover,
  .nav-link.active {
    color: #fff;
    background: rgba(255, 255, 255, .1);
  }

  .nav-item {
    margin-bottom: .25rem;
  }
  </style>
  @stack('styles')
</head>

<body>
  <div class="wrapper">
    <!-- Sidebar -->
    <aside class="main-sidebar">
      <div class="brand">TSMS</div>
      @include('layouts.navigation')
    </aside>

    <div class="main-content">
      <div class="container-fluid">
        @yield('content')
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  @stack('scripts')
</body>

</html>