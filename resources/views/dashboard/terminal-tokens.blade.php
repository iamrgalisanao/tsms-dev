@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header">
    <h5>Terminal Tokens</h5>
  </div>
  <div class="card-body">
    <!-- Filters -->
    <div class="mb-4">
      <form method="GET" action="{{ route('terminal-tokens') }}" class="row g-3">
        <div class="col-md-4">
          <label for="terminal_id" class="form-label">Terminal ID</label>
          <input type="text" class="form-control" id="terminal_id" name="terminal_id" value="{{ request('terminal_id') }}">
        </div>
        <div class="col-md-4">
          <label for="status" class="form-label">Status</label>
          <select class="form-select" id="status" name="status">
            <option value="">All Statuses</option>
            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
            <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
            <option value="revoked" {{ request('status') === 'revoked' ? 'selected' : '' }}>Revoked</option>
          </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-primary me-2">Filter</button>
          <a href="{{ route('terminal-tokens') }}" class="btn btn-secondary">Reset</a>
        </div>
      </form>
    </div>

    <!-- Terminal Tokens Table -->
    <div class="table-responsive">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>Terminal ID</th>
            <th>Tenant</th>
            <th>Created</th>
            <th>Expires At</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($terminals as $terminal)
          <tr>
            <td>{{ $terminal->terminal_uid }}</td>
            <td>{{ $terminal->tenant->name ?? 'Unknown' }}</td>
            <td>{{ $terminal->created_at->format('Y-m-d H:i') }}</td>
            <td>
              @if($terminal->expires_at)
                {{ $terminal->expires_at->format('Y-m-d H:i') }}
              @else
                Never
              @endif
            </td>
            <td>
              @if($terminal->is_revoked)
                <span class="badge bg-danger">Revoked</span>
              @elseif($terminal->expires_at && $terminal->expires_at->isPast())
                <span class="badge bg-warning">Expired</span>
              @else
                <span class="badge bg-success">Active</span>
              @endif
            </td>
            <td>
              <form method="POST" action="{{ route('terminal-tokens.regenerate', $terminal->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">Regenerate JWT</button>
              </form>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="6" class="text-center">No terminals found</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4 d-flex justify-content-center">
      @if($terminals->hasPages())
        <nav aria-label="Page navigation">
          <ul class="pagination">
            {{-- Previous Page Link --}}
            @if($terminals->onFirstPage())
              <li class="page-item disabled">
                <span class="page-link">«</span>
              </li>
            @else
              <li class="page-item">
                <a class="page-link" href="{{ $terminals->previousPageUrl() }}" rel="prev">«</a>
              </li>
            @endif

            {{-- Pagination Elements --}}
            @foreach($terminals->getUrlRange(1, $terminals->lastPage()) as $page => $url)
              @if($page == $terminals->currentPage())
                <li class="page-item active">
                  <span class="page-link">{{ $page }}</span>
                </li>
              @else
                <li class="page-item">
                  <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                </li>
              @endif
            @endforeach

            {{-- Next Page Link --}}
            @if($terminals->hasMorePages())
              <li class="page-item">
                <a class="page-link" href="{{ $terminals->nextPageUrl() }}" rel="next">»</a>
              </li>
            @else
              <li class="page-item disabled">
                <span class="page-link">»</span>
              </li>
            @endif
          </ul>
        </nav>
      @endif
    </div>
  </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mt-4" role="alert">
  {{ session('success') }}
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show mt-4" role="alert">
  {{ session('error') }}
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif
@endsection

@section('styles')
<style>
/* Additional styling for the pagination */
.pagination {
  margin-bottom: 0;
}

.page-item.active .page-link {
  background-color: #0d6efd;
  border-color: #0d6efd;
}

.page-link {
  color: #0d6efd;
}

.page-link:hover {
  color: #0a58ca;
}

/* Fix for badges */
.badge {
  font-size: 0.75em;
  padding: 0.35em 0.65em;
}
</style>
@endsection