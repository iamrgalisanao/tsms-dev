<!-- Brand Logo -->
{{-- <a href="#" class="bg-light-info brand-link">
  <img src="{{ asset('images/pitx_logo.png') }}" alt="AdminLTE Logo"
       class="brand-image" style="opacity:.8">
  <span class="brand-text font-weight-light">PITX - TSMS</span>
</a> --}}


<a href="#" class=" brand-link d-flex justify-content-center align-items-center">
  <img src="{{ asset('images/pitx_logo.png') }}" alt="PITX Logo"
       class="brand-image" style=" margin: 0; padding: 2px; float: none;">
</a>

<!-- Sidebar -->
<div class="sidebar bg-danger d-flex flex-column" style="height:100vh">
  <!-- Sidebar user panel -->
  <div class="user-panel mt-5 pb-3 mb-3 d-flex">
    <div class="image">
      <img src="{{ asset('dist/img/user2-160x160.jpg') }}"
           class="img-circle elevation-2" alt="User Image">
    </div>
    <div class="info">
      <a href="#" class="d-block text-white">Welcome! {{ auth()->user()->name ?? 'User' }}</a>
      {{-- <span class="text-muted">
        {{ is_object(auth()->user()->role)
            ? ucfirst(auth()->user()->role->name)
            : ucfirst(auth()->user()->role ?? 'User') }}
      </span> --}}
    </div>
  </div>

  {{-- Determine user role --}}
  @php
    $role = is_object(auth()->user()->role)
        ? strtolower(auth()->user()->role->name)
        : strtolower(auth()->user()->role ?? 'user');
  @endphp

  <!-- Sidebar Menu -->
  <nav class=" d-flex flex-column flex-grow-1">
    <ul class="nav nav-pills nav-sidebar flex-column"
        data-widget="treeview"
        role="menu"
        data-accordion="false">
        {{-- Dashboard --}}
         <li class="nav-item">
            <a href="{{ route('dashboard') }}" class="nav-link {{ Request::routeIs('dashboard') ? 'active' : '' }}">
            <i class="nav-icon fas fa-tachometer-alt text-white"></i> 
            <p class="text-white">Dashboard</p>
            </a>
        </li>

    @if(auth()->user() && auth()->user()->hasAnyRole(['admin', 'manager']))
    <li class="nav-item">
      <a href="{{ route('transactions.logs.index') }}"
      class="nav-link {{ Request::routeIs('transactions.logs.*') ? 'active' : '' }}">
      <i class="nav-icon fas fa-exchange-alt text-white"></i> 
      <p class="text-white">Transaction Logs</p>
      </a>
    </li>
    @endif

        {{-- <li class="nav-item">
            <a href="{{ route('transactions.logs.index') }}"
            class="nav-link {{ Request::routeIs('transactions.logs.*') ? 'active' : '' }}">
            <i class="nav-icon fas fa-exchange-alt text-white"></i> 
            <p class="text-white">Transaction Logs</p>
            </a>
        </li>

        <li class="nav-item">
            <a href="{{ route('dashboard.retry-history') }}"
            class="nav-link {{ Request::routeIs('dashboard.retry-history') ? 'active' : '' }}">
            <i class="nav-icon fas fa-sync text-white"></i> 
            <p class="text-white"> History </p>
            </a>
        </li> --}}

        <li class="nav-item">
            <a href="{{ route('system-logs.index') }}" class="nav-link {{ Request::routeIs('system-logs.*') ? 'active' : '' }}">
            <i class="nav-icon fas fa-history text-white"></i> 
            <p class="text-white">System Logs</p>
            </a>
        </li>

        {{-- <li class="nav-item">
            <a href="{{ route('circuit-breakers') }}"
            class="nav-link {{ Request::routeIs('circuit-breakers') ? 'active' : '' }}">
            <i class="nav-icon fas fa-shield-alt"></i> Circuit Breakers
            </a>
        </li> --}}

        <li class="nav-item">
            <a href="{{ route('terminal-tokens') }}"
            class="nav-link {{ Request::routeIs('terminal-tokens') ? 'active' : '' }}">
            <i class="nav-icon fas fa-key text-white"></i> 
            <p class="text-white">Terminal Tokens</p>
            </a>
        </li>

        {{-- User Management --}}
        @if(auth()->user() && auth()->user()->hasAnyRole(['admin', 'manager']))
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ Request::routeIs('users.*') ? 'active' : '' }}">
                <i class="nav-icon fas fa-users text-white"></i>
                <p class="text-white">User Management</p>
            </a>
        </li>
        @endif

      {{-- Logout sticks to bottom --}}
      <li class="nav-item d-sm-inline-block mt-auto">
        <a href="{{ route('logout') }}"
           class="nav-link"
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
          <i class="nav-icon fas fa-sign-out-alt text-white"></i>
          <p class="text-white">Logout</p>
        </a>
      </li>
    </ul>
  </nav>

  {{-- Hidden logout form --}}
  <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
    @csrf
  </form>
</div>
