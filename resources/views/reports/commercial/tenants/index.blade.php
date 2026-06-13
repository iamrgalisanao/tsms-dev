@extends('layouts.master')
@section('title', 'Tenants List')
@push('styles')
<style>
  /* Keep action buttons from wrapping and ensure header controls align */
  .tenants-table .btn { white-space: nowrap; }
  .tenants-header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .tenants-controls { display:flex; align-items:center; gap:8px; }
  /* Ensure DataTables responsive container doesn't leave large whitespace */
  .dataTables_wrapper .row { margin: 0; }
  /* Small tweak to align the export button with other controls */
  .btn-export { min-width: 90px; }
</style>
@endpush
@section('content')
@php
  // More robust detection of Laravel pagination
  use Illuminate\Pagination\LengthAwarePaginator;
  $serverPaginated = $tenants instanceof LengthAwarePaginator;
@endphp
<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Tenants List</h3>
  </div>
  <div class="card-body">
      <div class="tenants-header mb-3">
      <div>
        <label class="mb-0">Showing all tenants in the system</label>
      </div>
      <div class="tenants-controls">
        <a href="{{ route('commercial.sales-report.tenants.export') }}" class="btn btn-success btn-sm btn-export" title="Export CSV">
          <i class="fa fa-file-csv"></i> Export
        </a>
        {{-- Keep a lightweight search only when server-paginated (Laravel paginator) --}}
        @if($serverPaginated)
          <input id="tenant-search" class="form-control form-control-sm" placeholder="Search..." style="max-width:260px;" />
        @endif
      </div>
    </div>

    <div class="table-responsive">
  <table class="table table-striped table-hover table-bordered table-sm tenants-table" id="tenants-table" data-server-paginated="{{ $serverPaginated ? 'true' : 'false' }}">
        <thead>
          <tr class="table-primary">
            <th>Tenant Code</th>
            <th>Tenant ID</th>
            <th>Trade Name</th>
            <th>Location Type</th>
            <th>Level</th>
            <th>Unit No.</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($tenants as $tenant)
            <tr>
              <td>{{ $tenant->customer_code }}</td>
              <td>{{ $tenant->id }}</td>
              <td>{{ $tenant->trade_name }}</td>
              <td>{{ $tenant->location_type ?? 'inline' }}</td>
              <td>{{ $tenant->level ?? '' }}</td>
              <td>{{ $tenant->unit_no ?? '' }}</td>
              <td class="text-center">
                <a href="{{ route('commercial.sales-report.tenants.show', $tenant->id) }}" class="btn btn-info btn-sm" title="View"><i class="fa fa-eye"></i> View</a>
                {{-- Optional: edit/export buttons could be added here later --}}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
      <div class="d-flex align-items-center justify-content-between mt-2">
      <div>
        @if(method_exists($tenants, 'total'))
          <small class="text-muted">Showing {{ $tenants->firstItem() ?? 0 }} to {{ $tenants->lastItem() ?? 0 }} of {{ $tenants->total() }} entries</small>
        @endif
      </div>
      <div>
        @if(method_exists($tenants, 'links'))
          <div class="server-paginator">{{ $tenants->links() }}</div>
        @endif
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
  (function(){
    // Wire a lightweight search input to filter the table rows when server-paginated
    const search = document.getElementById('tenant-search');
    const table = document.getElementById('tenants-table');
    if (!table) return;

    const serverPaginated = table.dataset.serverPaginated === 'true';

    if (search && serverPaginated) {
      search.addEventListener('input', function(e){
        const q = (e.target.value || '').toLowerCase();
        Array.from(table.tBodies[0].rows).forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
      });
    }

    // If DataTables is available, initialize it. When server pagination is used
    // we disable paging so Laravel's paginator is the single source of truth.
    if (window.jQuery && $.fn.DataTable) {
      const dtOptions = {
        paging: !serverPaginated,
        searching: !serverPaginated, // let DataTables render its own search when it handles paging
        info: !serverPaginated,
        responsive: true,
        autoWidth: false,
        lengthChange: true,
        ordering: false,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      };

      const dt = $('#tenants-table').DataTable(dtOptions);

      // If DataTables handles paging, hide the server-side paginator to avoid duplicate controls
      if (!serverPaginated) {
        document.querySelectorAll('.server-paginator').forEach(el => el.style.display = 'none');
      }

      // Move DataTables' search input into our header area for consistent layout
      try {
        const dtSearchWrapper = document.querySelector('#tenants-table_filter');
        const headerControls = document.querySelector('.tenants-controls');
        if (dtSearchWrapper && headerControls) {
          // Move only the input element and adopt AdminLTE sizing classes
          const input = dtSearchWrapper.querySelector('input');
          if (input) {
            input.classList.add('form-control', 'form-control-sm');
            input.style.maxWidth = '260px';
            // append input into our header controls
            headerControls.appendChild(input);
            // remove the original wrapper (prevents duplicate elements)
            dtSearchWrapper.remove();
          } else {
            // fallback: move the entire wrapper
            headerControls.appendChild(dtSearchWrapper);
          }
        }
        // When DataTables handles searching, hide the server-side search input if present
        const localSearch = document.getElementById('tenant-search');
        if (localSearch) localSearch.style.display = (!serverPaginated ? 'none' : '');
      } catch (err) {
        // ignore DOM move errors
      }
    }
  })();
</script>
@endpush

@endsection
