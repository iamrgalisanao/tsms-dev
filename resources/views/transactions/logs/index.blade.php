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

@php
use App\Helpers\LogHelper;
use App\Helpers\BadgeHelper;
use App\Helpers\FormatHelper;
@endphp

<div class="card card-primary card-outline">
    <div class="card-header">
    <h3 class="card-title">Transaction Logs</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-toggle="collapse" data-target="#filtersCollapse" aria-expanded="false" aria-controls="filtersCollapse" title="Toggle Filters">
                <i class="fas fa-filter"></i>
            </button>
            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse Card">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>

    <div class="card-body">
    <div id="filtersCollapse" class="collapse show">
        <form method="GET" action="{{ route('transactions.logs.index') }}">
            <div class="form-row align-items-end">
                <div class="form-group col-sm-6 col-md-4 col-lg-3">
                    <label class="small text-muted mb-1">Tenant</label>
                    <select name="tenant_id" class="form-control form-control-sm">
                        <option value="">Any</option>
                        @isset($tenants)
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}" {{ (string)request('tenant_id') === (string)$tenant->id ? 'selected' : '' }}>
                                {{ $tenant->trade_name }}
                            </option>
                        @endforeach
                        @endisset
                    </select>
                </div>
                <div class="form-group col-sm-6 col-md-3 col-lg-2">
                    <button class="btn btn-primary btn-sm mr-2" type="submit"><i class="fas fa-check mr-1"></i> Apply</button>
                    <a href="{{ route('transactions.logs.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                </div>
            </div>
        </form>
        </div>
    </div>

        <div class="card-body pt-0">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item">
                    <a class="nav-link {{ ($activeTab ?? 'detailed') === 'detailed' ? 'active' : '' }}" href="{{ route('transactions.logs.index', request()->all()) }}">Detailed</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ ($activeTab ?? 'detailed') === 'summary' ? 'active' : '' }}" href="{{ route('transactions.logs.summary', request()->all()) }}">Summary</a>
                </li>
            </ul>
            <div id="dtBtnContainer" class="mb-2"></div>
            <div class="table-responsive p-0">
        @if(($activeTab ?? 'detailed') === 'summary')
        <table id="transactionSummaryTable" class="table table-striped table-hover table-head-fixed text-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Tenant</th>
                    <th>Terminal</th>
                    <th>Tx Count</th>
                    <th>Gross</th>
                    <th>VAT</th>
                    <th>Net</th>
                    <th>Refund</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($summary ?? []) as $row)
                <tr>
                    <td>{{ $row->date }}</td>
                    <td>{{ $row->trade_name }}</td>
                    <td>SN: {{ $row->serial_number ?? 'N/A' }} • M: {{ $row->machine_number ?? 'N/A' }}</td>
                    <td class="text-end">{{ number_format($row->tx_count) }}</td>
                    <td class="text-end">₱{{ number_format($row->gross, 2) }}</td>
                    <td class="text-end">₱{{ number_format($row->vat, 2) }}</td>
                    <td class="text-end">₱{{ number_format($row->net, 2) }}</td>
                    <td class="text-end">₱{{ number_format($row->refund, 2) }}</td>
                </tr>
                @empty
                @endforelse
            </tbody>
        </table>
        @else
        <table id="transactionLogsTable" class="table table-striped table-hover table-head-fixed text-sm">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Tenant / Terminal</th>
                    <th>Amount</th>
                    <th>Status</th>
                    {{-- <th>Job Status</th> --}}
                    <!-- {{-- <th>Attempts</th> --}} -->
                    <!-- <th>Transaction Count</th> -->
                    <th>Completed At</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr>
                    <td>{{ substr($log->transaction_id, -8) }}</td>
                    {{-- <td>{{ $log->terminal->identifier ?? 'N/A' }}</td>
                    <td> --}}
                    <td>
                        @php
                            $tenantName = optional(optional($log->terminal)->tenant)->trade_name;
                            $serial = optional($log->terminal)->serial_number;
                            $machine = optional($log->terminal)->machine_number;
                        @endphp
                        @if($tenantName)
                            <strong>{{ $tenantName }}</strong>
                        @else
                            <span class="text-danger">Unknown Tenant</span>
                        @endif
                        <div class="small text-muted">
                            SN: {{ $serial ?? 'N/A' }} • Machine: {{ $machine ?? 'N/A' }}
                        </div>
                    </td>
                    <!-- {{-- <td>{{ $log->terminal->terminal_uid ?? 'N/A' }}</td> --}} -->
                    <td class="text-end">₱{{ number_format($log->amount, 2) }}</td>
                    <td class="text-center">
                        @if($log->latest_job_status === 'FAILED')
                            <span class="badge badge-danger">FAILED</span>
                        @else
                            {!! BadgeHelper::getValidationStatusBadge($log->validation_status) . ' + ' . BadgeHelper::getJobStatusBadge($log->latest_job_status, 'job') !!}
                        @endif
                    </td>
                    {{-- <td class="text-center">{!! BadgeHelper::getJobStatusBadge($log->latest_job_status, 'job') !!}</td> --}}
                    <!-- {{-- <td class="text-center">{{ $log->job_attempts }}</td> --}}
                    {{-- <td class="text-center">{{ FormatHelper::formatDate($log->completed_at) }}</td> --}} -->
                    <!-- <td class="text-center">{{ $log->transaction_count }}</td> -->
                    <td class="text-center">{{ \Carbon\Carbon::parse($log->completed_at)->format('Y-m-d H:i:s') }}</td>
                    <td class="text-center">{{ \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i:s') }}</td>
                    <td class="text-center">
                        <a href="{{ route('transactions.logs.show', $log->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @if($log->validation_status === 'ERROR' && Gate::check('retry-transactions'))
                            <form action="{{ route('transactions.retry', $log->id) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-warning">Retry</button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                {{-- <tr>
                    <td colspan="8" class="text-center">No transactions found</td>
                </tr> --}}
                @endforelse
            </tbody>
                </table>
        @endif
            </div>
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
    const isSummary = {{ json_encode(($activeTab ?? 'detailed') === 'summary') }};
    const selector = isSummary ? '#transactionSummaryTable' : '#transactionLogsTable';
    if (!$(selector).length) return;
    if ($.fn.DataTable.isDataTable(selector)) return;
    const canExport = {!! json_encode(Gate::check('export-transaction-logs')) !!};
    const dt = $(selector).DataTable({
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
        "buttons": (canExport ? [
            { extend: "csv",   text: '<i class="fas fa-file-csv mr-1"></i> CSV',   className: "btn btn-primary btn-sm" },
            { extend: "excel", text: '<i class="far fa-file-excel mr-1"></i> Excel', className: "btn btn-success btn-sm" },
            { extend: "pdf",   text: '<i class="far fa-file-pdf mr-1"></i> PDF',   className: "btn btn-danger btn-sm" },
            { extend: "colvis",text: '<i class="fas fa-columns mr-1"></i> Cols',  className: "btn btn-secondary btn-sm" }
        ] : [{ extend: "colvis", text: '<i class="fas fa-columns mr-1"></i> Cols', className: "btn btn-secondary btn-sm" }])
     });

    // Move DataTables buttons into our container for AdminLTE layout
    dt.buttons().container().appendTo('#dtBtnContainer');

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