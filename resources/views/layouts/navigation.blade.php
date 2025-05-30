<nav class="navbar-nav">
  <li class="nav-item">
    <a href="{{ route('dashboard') }}" class="nav-link {{ Request::routeIs('dashboard') ? 'active' : '' }}">
      <i class="nav-icon fas fa-tachometer-alt"></i> Dashboard
    </a>
  </li>

  <li class="nav-item">
    <a href="{{ route('transactions.logs.index') }}"
      class="nav-link {{ Request::routeIs('transactions.logs.*') ? 'active' : '' }}">
      <i class="nav-icon fas fa-exchange-alt"></i> Transaction Logs
    </a>
  </li>

  <li class="nav-item">
    <a href="{{ route('dashboard.retry-history') }}"
      class="nav-link {{ Request::routeIs('dashboard.retry-history') ? 'active' : '' }}">
      <i class="nav-icon fas fa-sync"></i> Retry History
    </a>
  </li>

  <li class="nav-item">
    <a href="{{ route('system-logs.index') }}" class="nav-link {{ Request::routeIs('system-logs.*') ? 'active' : '' }}">
      <i class="nav-icon fas fa-history"></i> System Logs
    </a>
  </li>

  <li class="nav-item">
    <a href="{{ route('circuit-breakers') }}"
      class="nav-link {{ Request::routeIs('circuit-breakers') ? 'active' : '' }}">
      <i class="nav-icon fas fa-shield-alt"></i> Circuit Breakers
    </a>
  </li>

  <li class="nav-item">
    <a href="{{ route('terminal-tokens') }}"
      class="nav-link {{ Request::routeIs('terminal-tokens.*') ? 'active' : '' }}">
      <i class="nav-icon fas fa-key"></i> Terminal Tokens
    </a>
  </li>

  @if(Auth::check())
  <li class="nav-item mt-auto">
    <form action="{{ route('logout') }}" method="POST">
      @csrf
      <button type="submit" class="nav-link border-0 bg-transparent w-100 text-start">
        <i class="nav-icon fas fa-sign-out-alt"></i> Logout
      </button>
    </form>
  </li>
  @endif
</nav>