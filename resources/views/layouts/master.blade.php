<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- CSRF token for JS (axios) and other libraries -->
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>TSMS</title>

  <!-- Google Font: Source Sans Pro -->

  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ asset('plugins/fontawesome-free/css/all.min.css') }}">
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- Tempusdominus Bootstrap 4 -->
  <link rel="stylesheet" href="{{ asset('plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css') }}">
  <!-- iCheck -->
  <link rel="stylesheet" href="{{ asset('plugins/icheck-bootstrap/icheck-bootstrap.min.css') }}">
  <!-- JQVMap -->
  <link rel="stylesheet" href="{{ asset('plugins/jqvmap/jqvmap.min.css') }}">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ asset('dist/css/adminlte.min.css') }}">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="{{ asset('plugins/overlayScrollbars/css/OverlayScrollbars.min.css') }}">
  <!-- DataTables (AdminLTE / Bootstrap4 integration) -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="{{ asset('plugins/daterangepicker/daterangepicker.css') }}">
  <!-- summernote -->
  <link rel="stylesheet" href="{{ asset('plugins/summernote/summernote-bs4.min.css') }}">
  <!-- Stack for page-specific styles -->
  @stack('styles')

  <!-- Shared chart spinner & no-data styles -->
  <style>
    .chart-wrapper { position: relative; }
    .chart-spinner, .chart-no-data {
      display: none;
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255,255,255,0.65);
      z-index: 10;
      pointer-events: none;
      transition: opacity 200ms ease-in-out;
      opacity: 0;
    }
    /* When the wrapper is marked loading, show the overlay and allow it to block interactions */
    .chart-wrapper.is-loading .chart-spinner,
    .chart-wrapper.is-loading .chart-no-data {
      display: flex;
      opacity: 1;
      pointer-events: auto;
    }
    .chart-no-data {
      background: rgba(255,255,255,0.9);
      color: #6c757d;
      font-weight: 600;
      font-size: 0.95rem;
      pointer-events: none;
    }
    .chart-spinner .spinner-border { width: 2rem; height: 2rem; }
  </style>

  <!-- Add this CSS in the head section or in your CSS file -->

</head>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed layout-footer-fixed">

  <div class="wrapper">

    <!-- Preloader -->
    {{-- <div class="preloader flex-column justify-content-center align-items-center">
      <img class="animation__shake" src="{{ asset('dist/img/AdminLTELogo.png') }}" alt="AdminLTELogo" height="60"
        width="60">
    </div> --}}

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
      <!-- Left navbar links -->
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
          {{-- <a href="{{ route('admin.dashboard') }}" class="nav-link">Admin Dashboard</a> --}}
          {{-- @yield('title', 'Dashboard')S --}}
        </li>
        {{-- <li class="nav-item d-none d-sm-inline-block">
          <a href="#" class="nav-link">Contact</a>
        </li> --}}
      </ul>

    
    </nav>
    <!-- /.navbar -->

    <!-- Main Sidebar Container -->
    <aside class="main-sidebar sidebar-light-primary elevation-4">
    @include('layouts.partials.sidebar')
    </aside>

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
      <!-- Content Header (Page header) -->
      <div class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-6">
              <h1 class="m-0">@yield('title', 'Dashboard')</h1>
            </div><!-- /.col -->
            {{-- <div class="col-sm-6">
              @php
                $segments = request()->segments();
                $count    = count($segments);
                $isHome   = $count === 1
                            && in_array($segments[0],
                                ['admin','finance','commercial','executives','operations','tenant','it-support','transactions/logs','dashboard']
                            );
              @endphp

              @if($count > 0 && ! $isHome)
                @php
                  $url  = url('');
                  $last = $count - 1;
                @endphp

                <ol class="breadcrumb float-sm-right">
                  @foreach($segments as $i => $segment)
                    @php $url .= '/'.$segment; @endphp

                    @if($i === $last)
                      <li class="breadcrumb-item active">
                        {{ ucfirst($segment) }}
                      </li>
                    @else
                      <li class="breadcrumb-item">
                        <a href="{{ $url }}">{{ ucfirst($segment) }}</a>
                      </li>
                    @endif
                  @endforeach
                </ol>
              @endif
            </div> --}}
          </div><!-- /.row -->
        </div><!-- /.container-fluid -->
      </div>
      <!-- /.content-header -->

      <!-- Main content -->
      <section class="content">
        <div class="container-fluid">
          <!-- Small boxes (Stat box) -->
          @yield('content')
          <!-- /.row (main row) -->
        </div><!-- /.container-fluid -->
      </section>
      <!-- /.content -->
    </div>
    <!-- /.content-wrapper -->

    {{-- @include('partials.footer') --}}

    <!-- Control Sidebar -->
    <aside class="control-sidebar control-sidebar-dark">
      <!-- Control sidebar content goes here -->
    </aside>
    <!-- /.control-sidebar -->
  </div>
  <!-- ./wrapper -->

  <!-- Expose authenticated user (if any) to client scripts -->
  <script>
    window.authUser = @json(Auth::user());
  </script>

  <!-- jQuery -->
  <script src="{{ asset('plugins/jquery/jquery.min.js') }}"></script>
  <!-- Bootstrap 4 -->
  <script src="{{ asset('plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
  <!-- ChartJS: prefer Vite bundle when present, otherwise fall back to static plugin for servers without a build step -->
  <!-- Always load AdminLTE's Chart.js (v2) so inline legacy scripts and AdminLTE components use the same runtime. -->
  <script src="{{ asset('plugins/chart.js/Chart.min.js') }}"></script>
  @if (file_exists(public_path('build/manifest.json')))
    {{-- Load Vite-built app.js (it should NOT include Chart.js) --}}
    @vite(['resources/js/app.js'])
  @endif
  <!-- Sparkline -->
  <script src="{{ asset('plugins/sparklines/sparkline.js') }}"></script>
  <!-- JQVMap -->
  <script src="{{ asset('plugins/jqvmap/jquery.vmap.min.js') }}"></script>
  <script src="{{ asset('plugins/jqvmap/maps/jquery.vmap.usa.js') }}"></script>
  <!-- jQuery Knob Chart -->
  <script src="{{ asset('plugins/jquery-knob/jquery.knob.min.js') }}"></script>
  <!-- daterangepicker -->
  <script src="{{ asset('plugins/moment/moment.min.js') }}"></script>
  <script src="{{ asset('plugins/daterangepicker/daterangepicker.js') }}"></script>
  <!-- Tempusdominus Bootstrap 4 -->
  <script src="{{ asset('plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js') }}"></script>
  <!-- Summernote -->
  <script src="{{ asset('plugins/summernote/summernote-bs4.min.js') }}"></script>
  <!-- overlayScrollbars -->
  <script src="{{ asset('plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js') }}"></script>
  <!-- DataTables JS (core + Bootstrap4 + Responsive) -->
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
  <!-- AdminLTE App -->
  <script src="{{ asset('dist/js/adminlte.js') }}"></script>
  <!-- AdminLTE for demo purposes -->
  <!-- <script src="{{ asset('dist/js/demo.js') }}"></script> -->
  <!-- AdminLTE dashboard demo (This is only for demo purposes) -->
  {{-- <script src="{{ asset('dist/js/pages/dashboard.js') }}"></script> --}}
  <!-- Stack for page-specific scripts -->
  @stack('scripts')
</body>

</html>