@extends('layouts.master')
@section('title', 'Tenants List')
@push('styles')
<style>
  .tenants-table .btn { white-space: nowrap; }
  .tenants-header { display:flex; align-items:center; justify-content:space-between; }
</style>
@endpush
@section('content')
<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Tenants List</h3>
  </div>
  <div class="card-body">
      <div class="tenants-header mb-3">
      <div>
        <label>Showing all tenants in the system</label>
      </div>
      <div class="d-flex gap-2">
        <a href="{{ route('commercial.sales-report.tenants.export') }}" class="btn btn-success btn-sm mr-2" title="Export CSV">
          <i class="fa fa-file-csv"></i> Export
        </a>
        <input id="tenant-search" class="form-control form-control-sm" placeholder="Search..." style="max-width:260px;" />
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm tenants-table" id="tenants-table">
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
          {{ $tenants->links() }}
        @endif
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
  (function(){
    // Wire a lightweight search input to filter the table rows if DataTables isn't loaded.
    const search = document.getElementById('tenant-search');
    const table = document.getElementById('tenants-table');
    if (search && table) {
      search.addEventListener('input', function(e){
        const q = (e.target.value || '').toLowerCase();
        Array.from(table.tBodies[0].rows).forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
      });
    }

    // If DataTables is present use it to enhance the table
    if (window.jQuery && $.fn.DataTable) {
      // If we have server-side pagination enabled, defer to the rendered paginator
      // and disable DataTables' pagination to avoid double-paging.
      $('#tenants-table').DataTable({
        paging: false,
        searching: false,
        info: false,
        responsive: true,
        dom: 'Bfrtip',
        buttons: ['copy','csv','excel','pdf','print']
      });
    }
  })();
</script>
@endpush

@endsection
