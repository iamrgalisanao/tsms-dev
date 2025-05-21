<nav class="navbar-nav">
  <a href="{{ route('dashboard') }}" class="nav-link {{ Request::routeIs('dashboard') ? 'active' : '' }}">
    Dashboard
  </a>
  <!-- <a href="{{ route('providers.index') }}" class="nav-link {{ Request::routeIs('providers.*') ? 'active' : '' }}">
    Providers
  </a> -->
  <li class="nav-item">
    <a href="{{ route('transactions') }}" class="nav-link {{ request()->routeIs('transactions.*') ? 'active' : '' }}">
      <i class="nav-icon fas fa-exchange-alt"></i>
      <p>Transactions</p>
    </a>
  </li>
  <a href="{{ route('dashboard.retry-history') }}"
    class="nav-link {{ Request::routeIs('dashboard.retry-history') ? 'active' : '' }}">
    Retry History
  </a>
  <a href="{{ route('log-viewer') }}" class="nav-link {{ Request::routeIs('log-viewer.*') ? 'active' : '' }}">
    Logs
  </a>
  <a href="{{ route('circuit-breakers') }}" class="nav-link {{ Request::routeIs('circuit-breakers') ? 'active' : '' }}">
    Circuit Breakers
  </a>
  <a href="{{ route('terminal-tokens') }}" class="nav-link {{ Request::routeIs('terminal-tokens.*') ? 'active' : '' }}">
    Terminal Tokens
  </a>
</nav>