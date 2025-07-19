@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

  {{-- <div class="card">
    <div class="card-body">
      <table id="example1" class="table table-bordered table-striped">
          <thead>
              <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Resource</th>
                <th>Details</th>
                <th class="text-center">IP Address</th>
                <th class="text-center">Actions</th>
              </tr>
          </thead>
          <tbody>
            @forelse($auditLogs as $log)
            <tr>
              <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
              <td>{{ $log->user?->name ?? 'System' }}</td>
              <td>
                <span class="badge bg-{{ LogHelper::getActionTypeClass($log->action_type) }}">
                  @if(str_starts_with($log->action, 'auth.'))
                  @switch($log->action)
                  @case('auth.login')
                  <i class="fas fa-sign-in-alt me-1"></i>Login
                  @break
                  @case('auth.logout')
                  <i class="fas fa-sign-out-alt me-1"></i>Logout
                  @break
                  @case('auth.failed')
                  <i class="fas fa-exclamation-triangle me-1"></i>Failed Login
                  @break
                  @default
                  {{ $log->action }}
                  @endswitch
                  @else
                  {{ $log->action }}
                  @endif
                </span>
              </td>
              <td>{{ $log->resource_type }}</td>
              <td class="text-wrap" style="max-width: 300px;">
                <small class="text-muted">{{ $log->message }}</small>
              </td>
              <td class="text-center">{{ $log->ip_address }}</td>
              <td class="text-center">
                @if($log->metadata || $log->old_values)
                <button class="btn btn-sm btn-outline-primary" onclick="showContext('{{ $log->id }}')">
                  <i class="fas fa-search me-1"></i>Details
                </button>
                @endif
              </td>
            </tr>
            @empty
          
            @endforelse
          </tbody>
      </table>
    </div>
  </div> --}}
  
<div class="card">
     
    <div class="card-body">
        <table id="example2" class="table table-bordered table-striped">
            <thead>
                <tr>
                  <th>Time</th>
                  <th>Type</th>
                  <th>Severity</th>
                  {{-- <th>Terminal</th> --}}
                  <th>Messages</th>
                  {{-- <th>Transaction</th> --}}
                  {{-- <th class="text-center">Actions</th> --}}
                </tr>
            </thead>
            <tbody>
              @forelse($systemLogs as $log)
              <tr>
                <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                <td>
                  <span class="badge bg-{{ LogHelper::getLogTypeClass($log->log_type) }}">
                    {{ ucfirst($log->log_type) }}
                  </span>
                </td>
                <td>
                  <span class="badge bg-{{ BadgeHelper::getStatusBadgeColor($log->severity) }}">
                    {{ strtoupper($log->severity) }}
                  </span>
                </td>
                {{-- <td class="text-nowrap">{{ $log->terminal_uid ?? 'N/A' }}</td> --}}
                <td class="text-wrap" style="max-width: 300px;">
                  <small class="text-muted">{{ $log->message }}</small>
                </td>
                {{-- <td class="text-nowrap">
                  @if($log->transaction_id)
                  <a href="{{ route('transactions.show', $log->transaction_id) }}"
                    class="btn btn-sm btn-link text-decoration-none">
                    {{ $log->transaction_id }}
                  </a>
                  @else
                  <span class="text-muted">N/A</span>
                  @endif
                </td> --}}
                {{-- <td class="text-center">
                  @if($log->context)
                  <button class="btn btn-sm btn-outline-danger" onclick="showContext('{{ $log->id }}')">
                    <i class="fas fa-search me-1"></i> Details
                  </button>
                  @endif
                </td> --}}
              </tr>
              @empty
              {{-- <tr>
                <td colspan="7" class="text-center py-4">
                  <div class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>No system logs found
                  </div>
                </td>
              </tr> --}}
              @endforelse
            </tbody>
        </table>
    </div>
</div>

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

<script>
$(function () {
    $("#example2").DataTable({
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
              // { extend: "copy",  text: "Copy",  className: "btn btn-sm btn-outline-primary" },
              { extend: "csv",   text: "CSV",   className: "btn btn-danger" },
              { extend: "excel", text: "Excel", className: "btn btn-danger" },
              { extend: "pdf",   text: "PDF",   className: "btn btn-danger" },
              // { extend: "print", text: "Print", className: "btn btn-sm btn-danger" },
              { extend: "colvis",text: "Cols",  className: "btn btn-lg btn-danger" }
        ]
    }).buttons().container().appendTo('#example2_wrapper .col-md-6:eq(0)');

    // Toastr notifications
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif

    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
});
</script>
@endpush

