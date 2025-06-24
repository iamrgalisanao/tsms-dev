
@extends('layouts.master')
@section('title', 'Retry History')

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
        <h3 class="card-title text-white">Transaction History Details</h3>
    </div>
    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Terminal</th>
                    <th>Amount</th>
                    <th>Validation Status</th>
                    <th>Job Status</th>
                    <th>Attempts</th>
                    <th>Transaction Count</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($terminals as $terminal)
                <tr>
                    <td>{{ $terminal->transaction_id ?? '-' }}</td>
                    <td>{{ $terminal->terminal_uid ?? 'N/A' }}</td>
                    <td class="text-end">
                        @if(isset($terminal->gross_sales))
                            ₱{{ number_format($terminal->gross_sales, 2) }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-center">
                        {!! isset($terminal->validation_status) ? getStatusBadge($terminal->validation_status) : '-' !!}
                    </td>
                    <td class="text-center">
                        {!! isset($terminal->job_status) ? getStatusBadge($terminal->job_status, 'job') : '-' !!}
                    </td>
                    <td class="text-center">{{ $terminal->job_attempts ?? '-' }}</td>
                    <td class="text-center">{{ $terminal->transaction_count ?? '-' }}</td>
                    <td class="text-center">
                        {{ isset($terminal->completed_at) ? formatDate($terminal->completed_at) : '-' }}
                    </td>
                </tr>
                @empty
                {{-- <tr>
                    <td colspan="8" class="text-center">No transactions found</td>
                </tr> --}}
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

<script>
$(function () {
    $("#example1").DataTable({
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
    }).buttons().container().appendTo('#example1_wrapper .col-md-6:eq(0)');

    // Toastr notifications
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif

    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  function getStatusClass(status) {
    switch (status) {
      case 'QUEUED':     return 'bg-info';
      case 'PROCESSING': return 'bg-warning';
      case 'COMPLETED':  return 'bg-success';
      case 'FAILED':     return 'bg-danger';
      default:           return 'bg-secondary';
    }
  }

  function extractId(row) {
    // we know real rows have 8 cells, with ID in cell[0]
    const txt = row.cells[0].textContent.trim();
    if (/^(TX-)?\d+$/.test(txt)) {
      return txt.replace(/^TX-/, '');
    }
    return null;
  }

  function refreshRow(row) {
    const id = extractId(row);
    if (!id) return;  // placeholder or bad ID, skip

    fetch(`/api/v1/retry-history/${encodeURIComponent(id)}/status`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => {
      if (!r.ok) return null; // skip errors
      return r.json();
    })
    .then(json => {
      if (!json || json.status !== 'success') return;
      const d = json.data;
      // Job Status → 5th column (index 4)
      row.cells[4].innerHTML =
        `<span class="badge ${getStatusClass(d.job_status)}">${d.job_status}</span>`;
      // Attempts → 6th column (index 5)
      row.cells[5].textContent = d.job_attempts;
      // Updated At → 8th column (index 7)
      row.cells[7].textContent = new Date(d.updated_at).toLocaleString('en-US');
    })
    .catch(() => {/* ignore */});
  }

  function refreshAll() {
    document
      .querySelectorAll('#example1 tbody tr')
      .forEach(row => {
        // skip any row that doesn’t have 8 cells
        if (row.cells.length !== 8) return;
        refreshRow(row);
      });
  }

  // initial + polling
  refreshAll();
  setInterval(refreshAll, 30000);

  // optional real-time via Echo
  if (window.Echo) {
    Echo.channel('transaction-updates')
        .listen('TransactionRetryUpdated', () => {
          refreshAll();
        });
  }
});
</script>

@endpush