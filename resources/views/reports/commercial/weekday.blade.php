@extends('layouts.master')
@section('title', 'Weekday Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Weekday Sales Report (Mon–Fri)</h3>
  </div>
  <div class="card-body">
    {{-- Reuse weekly UI layout but call the weekday proxy endpoint from JS --}}
    @include('reports.commercial.weekly')
  </div>
</div>
@endsection

@push('scripts')
<script>
// The included weekly view already contains JS which calls the weekly proxy route.
// For weekday view we just override the AJAX URL at runtime so it calls the weekday proxy instead.
$(function() {
  // If the weekly script is present, replace the route endpoint used for AJAX calls
  if (typeof window !== 'undefined') {
    // Override the global selector action by patching jQuery ajax call target when loading
    // We modify the click handler's AJAX URL dynamically by setting a data attribute.
    $('#weekly-load-report').on('click', function(e) {
      // Change the endpoint to weekday proxy before delegating to existing handler logic
      const from = $('#week-date-from').val();
      const to = $('#week-date-to').val();
      const tenantId = $('#weekly-tenant-filter').val();
      if (!from || !to) { alert('Please select both from and to dates'); return; }
      $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
      $.ajax({
        url: '{{ route('commercial.sales-report.tsms-proxy.transactions.weekday') }}',
        data: { date_from: from, date_to: to, tenant_id: tenantId },
        success: function(resp) {
          // Reuse the same success code as weekly by calling the weekly load function if present
          if (typeof window.loadWeekly === 'function') {
            window.loadWeekly(from, to, tenantId);
          } else {
            // fallback: reload the page so included weekly view handles rendering
            location.reload();
          }
        },
        error: function() { alert('Failed to load weekday report'); },
        complete: function() { $('#weekly-load-report').prop('disabled', false).html('<i class="fa fa-search"></i> Load Report'); }
      });
    });
  }
});
</script>
@endpush
@extends('layouts.master')
@section('title', 'Weekday Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Weekday Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Weekday report UI goes here. Filter by weekdays and provide export options.</div>
  </div>
</div>
@endsection
