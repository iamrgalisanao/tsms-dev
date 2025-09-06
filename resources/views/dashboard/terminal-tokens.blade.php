@extends('layouts.master')
@section('title', 'Terminal Tokens')
@section('content')

@push('styles')
<!-- DataTables -->
<link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/toastr/toastr.min.css') }}">

@endpush

@section('content')


  <div class="card">
    <div class="card-header bg-primary">
        <h3 class="card-title text-white">List of Tokens</h3>
    </div>
    <div class="card-body">
        <table id="example3" class="table table-bordered table-striped">
            <thead>
                <tr>
                 
                  <th>Tenant</th>
                  <th>Tenant ID</th>
                  <th>Terminal ID</th>
                   <th>Serial Number</th>
                  <th>Created</th>
                  <th>Expires At</th>
                  <th>Status</th>
                  <th>API Key</th>
                  <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($terminals as $terminal)
                  <tr>
                    
                    <td>{{ $terminal->tenant->trade_name ?? 'Unknown' }}</td>
                    <td>{{ $terminal->tenant_id }}</td>
                    <td>{{ $terminal->id }}</td>
                    <td>{{ $terminal->serial_number }}</td>
                    <td>{{ $terminal->created_at ? $terminal->created_at->format('Y-m-d H:i') : 'N/A' }}</td>
                    <td>
                      @if(isset($terminal->expires_at) && $terminal->expires_at)
                        {{ \Carbon\Carbon::parse($terminal->expires_at)->format('Y-m-d H:i') }}
                      @else
                        Never
                      @endif
                    </td>
                    <td>
                      @if(isset($terminal->is_revoked) && $terminal->is_revoked)
                        <span class="badge bg-danger">Revoked</span>
                      @elseif(isset($terminal->expires_at) && $terminal->expires_at && \Carbon\Carbon::parse($terminal->expires_at)->isPast())
                        <span class="badge bg-warning">Expired</span>
                      @else
                        <span class="badge bg-success">Active</span>
                      @endif
                    </td>
                    <td>
                      @php
                        $latestToken = $terminal->tokens->last();
                      @endphp
                      <div class="input-group">
                        <input type="text" class="form-control" value="{{ $latestToken ? $latestToken->name : '' }}" readonly style="max-width: 180px;">
                        <span class="input-group-text" title="Token Created">{{ $latestToken ? $latestToken->created_at->format('Y-m-d H:i') : '' }}</span>
                      </div>
                    </td>
@if(session('bearer_token'))
  <div class="modal fade" id="newTokenModal" tabindex="-1" aria-labelledby="newTokenModalLabel" role="dialog" aria-modal="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="newTokenModalLabel">New API Token</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-success">
            <strong>Copy this token now. It will not be shown again:</strong>
            <input id="newApiTokenInput" type="text" class="form-control mt-2" value="{{ session('bearer_token') }}" readonly onclick="this.select();">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Wait for Bootstrap to be available then show modal and focus input after it's fully shown.
      function showWhenReady() {
        if (typeof bootstrap === 'undefined' || !document.getElementById('newTokenModal')) {
          setTimeout(showWhenReady, 50);
          return;
        }
        var modalEl = document.getElementById('newTokenModal');
        var newTokenModal = new bootstrap.Modal(modalEl);
        // Focus the input only after the modal 'shown' event to avoid focusing an element
        // while an ancestor is still aria-hidden (accessibility warning).
        modalEl.addEventListener('shown.bs.modal', function () {
          var tokenInput = document.getElementById('newApiTokenInput');
          if (tokenInput) {
            try { tokenInput.focus(); } catch (e) {}
          }
        }, { once: true });

        // Ensure we fully cleanup when modal is hidden so the page is not left blocked.
        modalEl.addEventListener('hidden.bs.modal', function () {
          try { newTokenModal.dispose(); } catch (e) {}
          // Remove Bootstrap modal-open class and any leftover backdrops
          document.body.classList.remove('modal-open');
          document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
          // Restore focus to a sensible element so keyboard users are not trapped
          var restore = document.querySelector('input[name="terminal_id"]') || document.querySelector('button') || document.body;
          try { restore && restore.focus(); } catch (e) {}
        }, { once: true });

        newTokenModal.show();
      }
      showWhenReady();
    });
  </script>
@endif
                    <td>
                      <form method="POST" action="{{ route('terminal-tokens.regenerate', $terminal->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning">Regenerate API Key</button>
                      </form>
                      <form method="POST" action="{{ route('terminal-tokens.revoke', $terminal->id) }}" class="d-inline ms-1">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to revoke this API key?')">Revoke API Key</button>
                      </form>
                    </td>
                  </tr>
                  @empty
                  <tr>
                    <td colspan="7" class="text-center">No terminals found</td>
                  </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>


@endsection

@push('scripts')
<!-- DataTables & Plugins -->
<script src="{{ asset('plugins/datatables/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-responsive/js/responsive.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/dataTables.buttons.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/jszip/jszip.min.js') }}"></script>
<script src="{{ asset('plugins/pdfmake/pdfmake.min.js') }}"></script>
<script src="{{ asset('plugins/pdfmake/vfs_fonts.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.html5.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.print.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-buttons/js/buttons.colVis.min.js') }}"></script>
<script src="{{ asset('plugins/toastr/toastr.min.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function () {
    $("#example3").DataTable({
        "responsive": true, 
        "lengthChange": false, 
        "autoWidth": false,
        "ordering": true,
        "info": true,
        "paging": true,
        "searching": true,
        "language": {
            "emptyTable": "No transaction logs available",
            "zeroRecords": "No matching records found",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "infoEmpty": "Showing 0 to 0 of 0 entries",
            "infoFiltered": "(filtered from _MAX_ total entries)",
            "search": "Search:",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        },
        "buttons": [
            { extend: "csv",   text: "CSV",   className: "btn btn-danger" },
              { extend: "excel", text: "Excel", className: "btn btn-danger" },
              { extend: "pdf",   text: "PDF",   className: "btn btn-danger" },
              // { extend: "print", text: "Print", className: "btn btn-sm btn-danger" },
              { extend: "colvis",text: "Cols",  className: "btn btn-lg btn-danger" }
        ]
    }).buttons().container().appendTo('#example3_wrapper .col-md-6:eq(0)');

    // Toastr notifications
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif

    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
    // API Key show/hide and copy functionality
    $(document).on('click', '.btn-show-api-key', function() {
      var input = $(this).siblings('.api-key-input');
      if (input.attr('type') === 'password') {
        input.attr('type', 'text');
        $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
      } else {
        input.attr('type', 'password');
        $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
      }
    });
    $(document).on('click', '.btn-copy-api-key', function() {
      var input = $(this).siblings('.api-key-input');
      input.attr('type', 'text'); // temporarily show
      input[0].select();
      document.execCommand('copy');
      input.attr('type', 'password');
      toastr.success('API Key copied to clipboard');
    });
});
</script>

@endpush

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