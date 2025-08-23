@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp


<div class="card">
     
    <div class="card-body">
  <table id="systemLogsTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                  <th>Time</th>
                  <th>Type</th>
                  <th>Severity</th>
                  <th>User</th>
                  {{-- <th>Terminal</th> --}}
                  <th>Message</th>
                  {{-- <th>Transaction</th> --}}
                  {{-- <th class="text-center">Actions</th> --}}
                </tr>
            </thead>
            <tbody>
              @forelse($systemLogs as $log)
              <tr>
                <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                <td>
                  @if($log->log_type || ($log->type === 'security' && isset($log->context['auth_event'])))
                    <span class="badge bg-{{ LogHelper::getLogTypeClass($log->log_type ?: 'security') }}">
                      @if($log->type === 'security' && isset($log->context['auth_event']))
                        @switch($log->context['auth_event'])
                          @case('login')
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                            @break
                          @case('logout')
                            <i class="fas fa-sign-out-alt me-1"></i>Logout
                            @break
                          @case('login_failed')
                            <i class="fas fa-exclamation-triangle me-1"></i>Failed Login
                            @break
                          @default
                            <i class="fas fa-shield-alt me-1"></i>Security
                        @endswitch
                      @else
                        {{ ucfirst($log->log_type ?: $log->type ?: 'System') }}
                      @endif
                    </span>
                  @else
                    <span class="badge bg-secondary">
                      <i class="fas fa-cog me-1"></i>{{ ucfirst($log->type ?: 'System') }}
                    </span>
                  @endif
                </td>
                <td>
                  @if($log->severity)
                    <span class="badge bg-{{ BadgeHelper::getStatusBadgeColor($log->severity) }}">
                      {{ strtoupper($log->severity) }}
                    </span>
                  @else
                    <span class="badge bg-info">
                      INFO
                    </span>
                  @endif
                </td>
                <td class="text-nowrap">{{ $log->user?->name ?? 'System' }}</td>
                <td class="text-wrap" style="max-width: 300px;">
                  <small class="text-muted">{{ $log->message }}</small>
                  @if($log->type === 'security' && isset($log->context['ip_address']))
                    <br><small class="text-info">IP: {{ $log->context['ip_address'] }}</small>
                  @endif
                </td>
                <td class="text-center">
                  <button class="btn btn-sm btn-outline-primary" onclick="showSystemLogContext('{{ $log->id }}')">
                    <i class="fas fa-search me-1"></i>Details
                  </button>
                </td>
              </tr>

              @empty
              <tr>
                <td colspan="6" class="text-center py-4">
                  <div class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>No system logs found
                  </div>
                </td>
              </tr>
              @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- SYSTEM LOG CONTEXT MODAL (moved outside table for global accessibility) -->
<div class="modal fade" id="systemLogContextModal" tabindex="-1" aria-labelledby="systemLogContextModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="systemLogContextModalLabel">
          <i class="fas fa-cogs me-2"></i>System Log Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-4">
          <div class="col-md-6">
            <h6><i class="fas fa-info-circle me-1"></i>Basic Information</h6>
            <table class="table table-sm">
              <tr><td><strong>Time:</strong></td><td id="systemlog-time"></td></tr>
              <tr><td><strong>User:</strong></td><td id="systemlog-user"></td></tr>
              <tr><td><strong>Type:</strong></td><td id="systemlog-type"></td></tr>
              <tr><td><strong>Severity:</strong></td><td id="systemlog-severity"></td></tr>
            </table>
          </div>
          <div class="col-md-6">
            <h6><i class="fas fa-comment me-1"></i>Message</h6>
            <div class="border rounded p-3 bg-light">
              <div id="systemlog-message"></div>
            </div>
          </div>
        </div>
        <div id="systemlogContextSection" style="display: none;">
          <hr>
          <h6><i class="fas fa-tags me-1"></i>Context</h6>
          <pre id="systemlog-context" class="bg-info bg-opacity-10 p-3 rounded"></pre>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>Close
        </button>
      </div>
    </div>
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
function showSystemLogContext(logId) {
  // Show loading state in each field
  $('#systemlog-time').text('');
  $('#systemlog-user').text('');
  $('#systemlog-type').html('');
  $('#systemlog-severity').html('');
  $('#systemlog-message').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading log details...</div>');
  $('#systemlogContextSection').hide();
  $('#systemLogContextModal').modal('show');

  // Fetch log details
  $.get('/log-viewer/system-context/' + logId)
    .done(function(data) {
      $('#systemlog-time').text(new Date(data.created_at).toLocaleString());
      $('#systemlog-user').text(data.user?.name || 'System');
      $('#systemlog-type').html('<span class="badge bg-primary">' + (data.log_type || data.type || 'System') + '</span>');
      $('#systemlog-severity').html('<span class="badge bg-info">' + (data.severity ? data.severity.toUpperCase() : 'INFO') + '</span>');
      $('#systemlog-message').text(data.message || 'No message');
      if (data.context) {
        $('#systemlogContextSection').show();
        $('#systemlog-context').text(typeof data.context === 'string' ? JSON.stringify(JSON.parse(data.context), null, 2) : JSON.stringify(data.context, null, 2));
      } else {
        $('#systemlogContextSection').hide();
      }
    })
    .fail(function(xhr) {
      let errorMessage = 'Failed to load log details';
      if (xhr.responseJSON && xhr.responseJSON.message) {
        errorMessage = xhr.responseJSON.message;
      }
      $('#systemlog-message').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + errorMessage + '</div>');
      $('#systemlogContextSection').hide();
    });
}

$(function () {
  const selector = '#systemLogsTable';
  if ($.fn.DataTable.isDataTable(selector)) return;

  $(selector).DataTable({
    responsive: true,
    lengthChange: false,
    autoWidth: false,
    ordering: true,
    info: true,
    paging: true,
    searching: true,
    order: [[0, 'desc']],

    // EXPLICIT: 6 columns to match <thead>
    columns: [
      { defaultContent: '' }, // Time
      { defaultContent: '' }, // Type
      { defaultContent: '' }, // Severity
      { defaultContent: '' }, // User
      { defaultContent: '' }, // Message
      { defaultContent: '' }  // Actions
    ],
    columnDefs: [
      { targets: -1, orderable: false, searchable: false },
      { targets: '_all', defaultContent: '' }
    ],

    language: {
      emptyTable: 'No system logs available',
      zeroRecords: 'No matching system logs found',
      info: 'Showing _START_ to _END_ of _TOTAL_ system log entries',
      infoEmpty: 'Showing 0 to 0 of 0 system log entries',
      infoFiltered: '(filtered from _MAX_ total system log entries)',
      search: 'Search system logs:',
      paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
    },
    buttons: [
      { extend: 'csv',   text: "<i class='fas fa-file-csv'></i> CSV",    className: 'btn btn-success btn-sm' },
      { extend: 'excel', text: "<i class='fas fa-file-excel'></i> Excel", className: 'btn btn-success btn-sm' },
      { extend: 'pdf',   text: "<i class='fas fa-file-pdf'></i> PDF",     className: 'btn btn-danger btn-sm' },
      { extend: 'colvis',text: "<i class='fas fa-columns'></i> Columns",  className: 'btn btn-info btn-sm' }
    ]
  }).buttons().container().appendTo('#systemLogsTable_wrapper .col-md-6:eq(0)');


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
