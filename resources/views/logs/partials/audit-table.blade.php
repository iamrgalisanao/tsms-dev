@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp

<!-- PROPER AUDIT TRAIL IMPLEMENTATION -->
<div class="card">
    @php
        $user = auth()->user();
        $isAdmin = $user && (method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'));
    @endphp
    <div class="card-header">
    <h3 class="card-title"><i class="fas fa-history mr-2"></i>Audit Trail</h3>
        <div class="card-tools">
            @if($isAdmin)
                <div class="d-flex align-items-center">
                    {{-- If we're on the archived logs route, present restore/purge actions --}}
                    @if(request()->routeIs('system-logs.archived'))
                        <a href="{{ route('system-logs.index') }}" class="btn btn-sm btn-outline-secondary mr-2">Back to Logs</a>
                        <button id="restoreSelected" class="btn btn-sm btn-primary mr-2">Restore Selected</button>
                        <button id="purgeSelected" class="btn btn-sm btn-danger mr-2">Permanently Purge Selected</button>
                    @else
                        <a href="{{ route('system-logs.archived') }}" class="btn btn-sm btn-outline-secondary mr-2">View Archived Logs</a>
                        <button id="archiveSelected" class="btn btn-sm btn-warning mr-2">Archive Selected</button>
                    @endif
                </div>
            @endif
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
    <table id="auditTable" class="table table-bordered table-striped">
          <thead>
              <tr>
                                <th style="width: 1%;"><input type="checkbox" id="audit-select-all" aria-label="Select all audit rows"></th>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Resource</th>
                {{-- <th>Tenant</th> --}}
                <th>Details</th>
                <th class="text-center">IP Address</th>
                <th class="text-center">Actions</th>
              </tr>
          </thead>
          <tbody>
                        @forelse($auditLogs as $log)
                            {{-- Skip soft-deleted (trashed) logs so they don't appear in the table --}}
                            @if(method_exists($log, 'trashed') && $log->trashed())
                                @continue
                            @endif
                        <tr>
                            <td><input type="checkbox" class="audit-row-checkbox" value="{{ $log->id }}" aria-label="Select audit row {{ $log->id }}"></td>
                            <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $log->user?->name ?? 'System' }}</td>
                            <td>
                                <span class="badge bg-{{ LogHelper::getActionTypeClass($log->action_type ?? 'default') }}">
                                    @if(str_starts_with($log->action, 'auth.'))
                                    @switch($log->action)
                                    @case('auth.login')
                                    <i class="fas fa-sign-in-alt mr-1"></i>Login
                                    @break
                                    @case('auth.logout')
                                    <i class="fas fa-sign-out-alt mr-1"></i>Logout
                                    @break
                                    @case('auth.failed')
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Failed Login
                                    @break
                                    @default
                                    {{ $log->action }}
                                    @endswitch
                                    @elseif(str_starts_with($log->action, 'TRANSACTION'))
                                    @switch($log->action)
                                    @case('TRANSACTION_RECEIVED')
                                    <i class="fas fa-inbox mr-1"></i>Transaction Received
                                    @break
                                    @case('TRANSACTION_VOID_POS')
                                    <i class="fas fa-ban mr-1"></i>Transaction Voided
                                    @break
                                    @case('TRANSACTION_PROCESSED')
                                    <i class="fas fa-check mr-1"></i>Transaction Processed
                                    @break
                                    @default
                                    <i class="fas fa-exchange-alt mr-1"></i>{{ $log->action }}
                                    @endswitch
                                    @else
                                    {{ $log->action }}
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info">{{ $log->resource_type ?? 'N/A' }}</span>
                                @if($log->resource_id)
                                    <br><small class="text-muted">{{ $log->resource_id }}</small>
                                @endif
                            </td>
                            {{-- <td>
                                @php $tenantName = $log->tenant_name ?? null; @endphp
                                @if($tenantName)
                                    <span class="badge bg-secondary">{{ $tenantName }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td> --}}
                            <td class="text-wrap" style="max-width: 300px;">
                                <small class="text-muted">{{ $log->message }}</small>
                                @if($log->old_values || $log->new_values)
                                    <br><small class="badge bg-warning">Data Changed</small>
                                @endif
                                {{-- Non-VAT Sale Badge --}}
                                @if(
                                    isset($log->metadata['taxes']) && is_array($log->metadata['taxes']) &&
                                    collect($log->metadata['taxes'])->contains(function($tax) { return $tax['tax_type'] === 'SC_VAT_EXEMPT_SALES' && $tax['amount'] > 0; }) &&
                                    collect($log->metadata['taxes'])->contains(function($tax) { return $tax['tax_type'] === 'VAT' && $tax['amount'] == 0; }) &&
                                    collect($log->metadata['taxes'])->contains(function($tax) { return $tax['tax_type'] === 'VATABLE_SALES' && $tax['amount'] == 0; })
                                )
                                    <br><small class="badge bg-info">Non-VAT Sale</small>
                                @endif
                            </td>
                            <td class="text-center">{{ $log->ip_address ?? 'N/A' }}</td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center flex-wrap">
                                    @if($log->action !== 'audit_log.viewed')
                                        <button class="btn btn-sm btn-outline-primary mr-1" onclick="showAuditContext('{{ $log->id }}')">
                                            <i class="fas fa-search mr-1"></i>Details
                                        </button>
                                    @endif

                                    {{-- Hard delete action: show only to admins and only for trashed logs --}}
                                    @if($isAdmin && method_exists($log, 'trashed') && $log->trashed())
                                        <form method="POST" action="{{ url('/system-logs/'.$log->id.'/hard-delete') }}" onsubmit="return confirm('Permanently delete this log? This action cannot be undone.');" style="display:inline-block; margin:0;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger mr-1" title="Permanently delete log">
                                                <i class="fas fa-trash-alt mr-1"></i>Purge
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        {{-- Intentionally render no rows when empty; DataTables will display its emptyTable message. --}}
                        @endforelse
          </tbody>
      </table>
        </div>
    </div>
</div>

<!-- AUDIT CONTEXT MODAL FOR DATA CHANGES -->
<div class="modal fade" id="auditContextModal" tabindex="-1" aria-labelledby="auditContextModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="auditContextModalLabel">
                    <i class="fas fa-history me-2"></i>Audit Trail Details
                </h5>
                <button type="button" class="btn-close close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Audit Log Basic Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-1"></i>Basic Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Time:</strong></td><td id="audit-time"></td></tr>
                            <tr><td><strong>User:</strong></td><td id="audit-user"></td></tr>
                            <tr><td><strong>Action:</strong></td><td id="audit-action"></td></tr>
                            <tr><td><strong>Resource:</strong></td><td id="audit-resource"></td></tr>
                            <tr><td><strong>Tenant:</strong></td><td id="audit-tenant"></td></tr>
                            <tr><td><strong>IP Address:</strong></td><td id="audit-ip"></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-comment me-1"></i>Message</h6>
                        <div class="border rounded p-3 bg-light">
                            <div id="audit-message"></div>
                        </div>
                    </div>
                </div>

                <!-- Data Changes Section -->
                <div id="dataChangesSection" style="display: none;">
                    <hr>
                    <h6><i class="fas fa-exchange-alt me-1"></i>Data Changes</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <h7><i class="fas fa-arrow-left me-1"></i>Before (Old Values):</h7>
                            <pre id="oldValues" class="bg-danger bg-opacity-10 p-3 rounded"></pre>
                        </div>
                        <div class="col-md-6">
                            <h7><i class="fas fa-arrow-right me-1"></i>After (New Values):</h7>
                            <pre id="newValues" class="bg-success bg-opacity-10 p-3 rounded"></pre>
                        </div>
                    </div>
                </div>

                <!-- Metadata Section -->
                <div id="metadataSection" style="display: none;">
                    <hr>
                    <h6><i class="fas fa-tags me-1"></i>Additional Context</h6>
                    <pre id="metadataContent" class="bg-info bg-opacity-10 p-3 rounded"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-dismiss="modal">
                    <i class="fas fa-times me-1 mr-2"></i>Close
                </button>
                {{-- <button type="button" class="btn btn-primary" onclick="exportAuditDetail()">
                    <i class="fas fa-download me-1"></i>Export Details
                </button> --}}
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
$(function () {
  const selector = '#auditTable';
  if ($.fn.DataTable.isDataTable(selector)) return;

        $(selector).DataTable({
    responsive: true,
    lengthChange: false,
    autoWidth: false,
    ordering: true,
    info: true,
    paging: true,
    searching: true,
    // Sort by Time (second column) by default — first column is selection checkboxes
    order: [[1, 'desc']],
            // Explicit column rules: checkbox col (0) should not be orderable; actions col (-1) not orderable
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, width: '1%' },
                { targets: -1, orderable: false, searchable: false },
                { targets: '_all', defaultContent: '' }
            ],

    language: {
      emptyTable: 'No audit logs available',
      zeroRecords: 'No matching audit records found',
      info: 'Showing _START_ to _END_ of _TOTAL_ audit entries',
      infoEmpty: 'Showing 0 to 0 of 0 audit entries',
      infoFiltered: '(filtered from _MAX_ total audit entries)',
      search: 'Search audit logs:',
      paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
    },
    buttons: [
      { extend: 'csv',   text: "<i class='fas fa-file-csv'></i> CSV",    className: 'btn btn-success btn-sm' },
      { extend: 'excel', text: "<i class='fas fa-file-excel'></i> Excel", className: 'btn btn-success btn-sm' },
      { extend: 'pdf',   text: "<i class='fas fa-file-pdf'></i> PDF",     className: 'btn btn-danger btn-sm' },
      { extend: 'colvis',text: "<i class='fas fa-columns'></i> Columns",  className: 'btn btn-info btn-sm' }
    ]
  }).buttons().container().appendTo('#auditTable_wrapper .col-md-6:eq(0)');
});


// Show audit context with data changes
function showAuditContext(auditId) {
    // Open modal and initialize placeholders without removing the DOM structure
    $('#auditContextModal').modal('show');
    $('#audit-time').text('Loading…');
    $('#audit-user').text('Loading…');
    $('#audit-action').html('<span class="badge bg-secondary">Loading…</span>');
    $('#audit-resource').html('<span class="badge bg-info">Loading…</span><br><small class="text-muted">N/A</small>');
    $('#audit-tenant').text('Loading…');
    $('#audit-ip').text('Loading…');
    $('#audit-message').text('Loading…');
    $('#dataChangesSection').hide();
    $('#metadataSection').hide();
    $('#oldValues').text('');
    $('#newValues').text('');
    $('#metadataContent').text('');

    // Fetch audit details (expect JSON; redirects or HTML will go to fail)
    $.ajax({
        url: '/log-viewer/audit-context/' + auditId,
        method: 'GET',
        dataType: 'json'
    })
    .done(function(data) {
        // Basic type guard in case middleware returned unexpected content
        if (!data || typeof data !== 'object') {
            throw new Error('Invalid response format');
        }

        // Populate basic info
        $('#audit-time').text(data.created_at ? new Date(data.created_at).toLocaleString() : 'N/A');
        $('#audit-user').text((data.user && data.user.name) ? data.user.name : 'System');
        $('#audit-action').html('<span class="badge bg-primary">' + (data.action || 'N/A') + '</span>');
        $('#audit-resource').html('<span class="badge bg-info">' + (data.resource_type || 'N/A') + '</span>' +
            (data.resource_id ? '<br><small class="text-muted">' + data.resource_id + '</small>' : '<br><small class="text-muted">N/A</small>'));
        const tn = data.tenant && (data.tenant.trade_name || data.tenant.id) ? (data.tenant.trade_name || ('Tenant #' + data.tenant.id)) : 'N/A';
        $('#audit-tenant').text(tn);
        $('#audit-ip').text(data.ip_address ? data.ip_address : 'N/A');
        $('#audit-message').text(data.message ? data.message : 'No message available');

        // Data changes section
        let oldValuesText = 'No old values available';
        let newValuesText = 'No new values available';
        if (data.old_values) {
            try {
                oldValuesText = JSON.stringify(JSON.parse(data.old_values), null, 2);
            } catch (e) {
                oldValuesText = typeof data.old_values === 'object' ? JSON.stringify(data.old_values, null, 2) : String(data.old_values);
            }
        }
        if (data.new_values) {
            try {
                newValuesText = JSON.stringify(JSON.parse(data.new_values), null, 2);
            } catch (e) {
                newValuesText = typeof data.new_values === 'object' ? JSON.stringify(data.new_values, null, 2) : String(data.new_values);
            }
        }
        $('#dataChangesSection').show();
        $('#oldValues').text(oldValuesText);
        $('#newValues').text(newValuesText);

        // Metadata section
        let metadataText = 'No metadata available';
        if (data.metadata) {
            try {
                if (typeof data.metadata === 'string') {
                    metadataText = JSON.stringify(JSON.parse(data.metadata), null, 2);
                } else {
                    metadataText = JSON.stringify(data.metadata, null, 2);
                }
            } catch (e) {
                metadataText = typeof data.metadata === 'object' ? JSON.stringify(data.metadata, null, 2) : String(data.metadata);
            }
        }
        $('#metadataSection').show();
        $('#metadataContent').text(metadataText);
    })
    .fail(function(xhr) {
        let errorMessage = 'Failed to load audit details';
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
        } else if (xhr && xhr.status === 401) {
            errorMessage = 'Your session has expired. Please sign in again.';
        }
        // Show error in a stable location
        $('#audit-message').text(errorMessage);
        $('#dataChangesSection').hide();
        $('#metadataSection').hide();
    });
}

// Export audit detail function
function exportAuditDetail() {
    const auditData = {
        time: $('#audit-time').text(),
        user: $('#audit-user').text(),
        action: $('#audit-action').text(),
        resource: $('#audit-resource').text(),
        ip: $('#audit-ip').text(),
        message: $('#audit-message').text(),
        oldValues: $('#oldValues').text(),
        newValues: $('#newValues').text(),
        metadata: $('#metadataContent').text()
    };
    
    const dataStr = JSON.stringify(auditData, null, 2);
    const dataBlob = new Blob([dataStr], {type: 'application/json'});
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'audit-detail-' + Date.now() + '.json';
    link.click();
    URL.revokeObjectURL(url);
    
    toastr.success('Audit details exported successfully');
}

// Accessibility fixes: avoid aria-hidden on an element that still retains focus.
// Problem: when the modal is closed Bootstrap may set aria-hidden on the modal
// while a child element still has focus, which triggers the browser/a11y warning.
// Solution: capture/restore focus and blur the active element immediately when
// a modal-close control is activated so the element doesn't remain focused
// while its ancestor is hidden.
(function(){
    var _lastAuditTrigger = null;

    // Remember the last focused trigger inside the audit table (best-effort)
    $(document).on('focusin', '#auditTable button, #auditTable a', function(){
        _lastAuditTrigger = this;
    });

    // When any modal-close control inside the modal is clicked, blur the active element
    $(document).on('click', '#auditContextModal [data-dismiss="modal"], #auditContextModal [data-bs-dismiss="modal"], #auditContextModal .btn-close', function(){
        try { if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); } catch(e){}
    });

    // When modal shown, move focus into the modal for keyboard users
    $('#auditContextModal').on('shown.bs.modal', function(){
        try {
            var mb = $(this).find('.modal-body');
            if (mb && mb.length) {
                mb.attr('tabindex', -1).focus();
            }
        } catch(e){}
    });

    // When modal is hidden, attempt to restore focus to the originating trigger
    $('#auditContextModal').on('hidden.bs.modal', function(){
        try {
            if (_lastAuditTrigger && typeof _lastAuditTrigger.focus === 'function') {
                _lastAuditTrigger.focus();
            }
        } catch(e){}
    });
})();

// Bulk archive (soft-delete) handling for audit table
$(function(){
    $('#audit-select-all').on('change', function(){
        $('.audit-row-checkbox').prop('checked', $(this).prop('checked'));
    });

    $('#archiveSelected').on('click', function(){
        var ids = $('.audit-row-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if (ids.length === 0) { alert('Select at least one row to archive'); return; }
        if(!confirm('Archive (soft-delete) selected logs?')) return;
        var form = $('#bulkSoftForm');
        // remove previous inputs
        form.find('input[name="ids[]"]').remove();
        ids.forEach(function(id){
            form.append($('<input>').attr({type:'hidden', name:'ids[]', value:id}));
        });
        form.submit();
    });
});

    // Hidden bulk action forms for archive/restore/purge operations
    // These forms are populated dynamically by the bulk action handlers above
    $(function(){
        if ($('#bulkSoftForm').length === 0) {
            $('body').append('<form id="bulkSoftForm" method="POST" action="' + '{{ route("system-logs.bulk-soft-delete") }}' + '" style="display:none">@csrf</form>');
        }
        if ($('#bulkRestoreForm').length === 0) {
            $('body').append('<form id="bulkRestoreForm" method="POST" action="' + '{{ route("system-logs.bulk-restore") }}' + '" style="display:none">@csrf</form>');
        }
        if ($('#bulkPurgeForm').length === 0) {
            $('body').append('<form id="bulkPurgeForm" method="POST" action="' + '{{ route("system-logs.bulk-purge") }}' + '" style="display:none">@csrf</form>');
        }
    });
</script>
@endpush

