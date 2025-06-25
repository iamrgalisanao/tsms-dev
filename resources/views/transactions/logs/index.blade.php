@extends('layouts.master')

@section('title', 'Transactions Logs')

@push('styles')
<!-- DataTables -->
<link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/toastr/toastr.min.css') }}">

@endpush



@section('content')

{{-- @php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
@endphp --}}

<div class="card">
    <div class="card-header bg-primary">
        <h3 class="card-title text-white">List of Transactions</h3>
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
                @forelse($logs as $log)
                <tr>
                    <td>{{ $log->transaction_id }}</td>
                    <td>{{ $log->terminal->identifier ?? 'N/A' }}</td>
                    <td class="text-end">₱{{ number_format($log->gross_sales, 2) }}</td>
                    <td class="text-center">{!! BadgeHelper::getValidationStatusBadge($log->validation_status) !!}</td>
                    <td class="text-center">{!! BadgeHelper::getJobStatusBadge($log->job_status, 'job') !!}</td>
                    <td class="text-center">{{ $log->job_attempts }}</td>
                    <td class="text-center">{{ $log->transaction_count }}</td>
                    <td class="text-center">{{ formatDate($log->completed_at) }}</td>
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
@endpush