@extends('layouts.master')
@section('title', 'Daily Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Daily Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="row mb-2 filter-row">
      <div class="col-md-12 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <strong class="mr-2">Date:</strong>
          <div class="input-group input-group-sm date mr-3" id="daily-report-date-picker" data-target-input="nearest" style="max-width: 160px; min-width: 140px;">
            <input type="text" id="daily-report-date" class="form-control form-control-sm datetimepicker-input" data-target="#daily-report-date-picker" placeholder="Select date" autocomplete="off"/>
            <div class="input-group-append" data-target="#daily-report-date-picker" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <strong class="mr-2">Select Tenant:</strong>
          <select id="daily-tenant-filter" class="form-control form-control-sm mr-3" style="max-width: 250px;">
            <option value="">-- Select Tenant --</option>
          </select>
          <button id="daily-load-report" class="btn btn-primary btn-sm mr-2"><i class="fa fa-search"></i> Load Report</button>
          <button id="daily-export-excel" class="btn btn-success btn-sm" disabled><i class="fa fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="text-right">
          <strong>Date Generated:</strong> <span id="daily-date-generated">MMM DD YYYY</span>
        </div>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <h6>Total Gross Sales</h6>
            <div id="summary-gross" class="h4">-</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <h6>Total Net Sales</h6>
            <div id="summary-net" class="h4">-</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <h6>Transaction Count</h6>
            <div id="summary-transactions" class="h4">-</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body text-center">
            <h6>Guest Count</h6>
            <div id="summary-guests" class="h4">-</div>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm" id="daily-hourly-table" style="font-size: 13px;">
        <thead>
          <tr class="table-primary">
            <th class="text-center">Hour</th>
            <th class="text-end">Gross Sales</th>
            <th class="text-end">Net Sales</th>
            <th class="text-center">Transactions</th>
            <th class="text-center">Guests</th>
          </tr>
        </thead>
        <tbody id="daily-report-tbody">
          <tr>
            <td colspan="5" class="text-center py-4 text-muted">Please select a date and tenant, then click "Load Report" to view the daily sales summary.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="table-warning fw-bold">
            <td class="text-center">Total</td>
            <td id="daily-total-gross" class="text-end">-</td>
            <td id="daily-total-net" class="text-end">-</td>
            <td id="daily-total-transactions" class="text-center">-</td>
            <td id="daily-total-guests" class="text-center">-</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
@endsection
@push('scripts')
<script>
$(function() {
  // Initialize datepicker
  try {
    $('#daily-report-date-picker').datetimepicker({
      format: 'YYYY-MM-DD',
      defaultDate: moment(),
      icons: { time: 'far fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check', clear: 'fa fa-trash', close: 'fa fa-times' }
    });
  } catch (err) {
    console.warn('Datepicker init failed for daily report:', err);
  }

  $('#daily-report-date').val(moment().format('YYYY-MM-DD'));
  $('#daily-date-generated').text(moment().format('MMM DD YYYY'));

  function loadTenants() {
    $.ajax({
      url: '{{ route('commercial.sales-report.tenants') }}',
      success: function(tenants) {
        const dropdown = $('#daily-tenant-filter');
        dropdown.empty().append('<option value="">-- Select Tenant --</option>');
        tenants.forEach(tenant => {
          dropdown.append(`<option value="${tenant.id}">${tenant.trade_name} (${tenant.customer_code})</option>`);
        });
      },
      error: function() { console.error('Failed to load tenants for daily report'); }
    });
  }
  loadTenants();

  function renderEmptyMessage() {
    $('#daily-report-tbody').html(`<tr><td colspan="5" class="text-center py-4 text-muted">No daily sales data available for the selected date and tenant.</td></tr>`);
    $('#daily-total-gross, #daily-total-net').text('-');
    $('#daily-total-transactions, #daily-total-guests').text('-');
    $('#summary-gross, #summary-net, #summary-transactions, #summary-guests').text('-');
    $('#daily-export-excel').prop('disabled', true);
  }

  function loadDailyReport(date, tenantId) {
    if (!date || !tenantId) {
      renderEmptyMessage();
      return;
    }

    $.ajax({
      url: '{{ route('commercial.sales-report.tsms-proxy.transactions.daily') }}',
      data: { date: date, tenant_id: tenantId },
      success: function(resp) {
        // Expect { summary: {...}, hours: [...] }
        if (!resp || !resp.summary) {
          renderEmptyMessage();
          return;
        }

        const s = resp.summary;
        $('#summary-gross').text((Number(s.gross_sales) || 0).toFixed(2));
        $('#summary-net').text((Number(s.net_sales) || 0).toFixed(2));
        $('#summary-transactions').text(String(Math.round(Number(s.transaction_count) || 0)));
        $('#summary-guests').text(String(Math.round(Number(s.guest_count) || 0)));

        // Render 24 rows
        const rowsByHour = {};
        if (Array.isArray(resp.hours)) {
          resp.hours.forEach(r => { rowsByHour[r.hour] = r; });
        }

        const tbody = $('#daily-report-tbody');
        tbody.empty();

        let totalGross = 0, totalNet = 0, totalTxn = 0, totalGuests = 0;

        for (let hour = 0; hour < 24; hour++) {
          const hourFormatted = String(hour).padStart(2, '0') + ':00';
          const row = rowsByHour[hourFormatted] || null;
          if (!row) {
            tbody.append(`<tr><td class="text-center">${hourFormatted}</td><td class="text-end">-</td><td class="text-end">-</td><td class="text-center">-</td><td class="text-center">-</td></tr>`);
            continue;
          }
          const gross = Number(row.gross_sales) || 0;
          const net = Number(row.net_sales) || 0;
          const tx = Math.round(Number(row.transaction_count) || 0);
          const guests = Math.round(Number(row.guest_count) || 0);

          totalGross += gross; totalNet += net; totalTxn += tx; totalGuests += guests;

          tbody.append(`<tr><td class="text-center">${hourFormatted}</td><td class="text-end">${gross.toFixed(2)}</td><td class="text-end">${net.toFixed(2)}</td><td class="text-center">${tx}</td><td class="text-center">${guests}</td></tr>`);
        }

        $('#daily-total-gross').text(totalGross.toFixed(2));
        $('#daily-total-net').text(totalNet.toFixed(2));
        $('#daily-total-transactions').text(String(totalTxn));
        $('#daily-total-guests').text(String(totalGuests));

        $('#daily-export-excel').prop('disabled', false);
      },
      error: function() { renderEmptyMessage(); },
      complete: function() { $('#daily-load-report').prop('disabled', false).html('<i class="fa fa-search"></i> Load Report'); }
    });
  }

  // Button handler
  $('#daily-load-report').on('click', function() {
    const date = $('#daily-report-date').val();
    const tenantId = $('#daily-tenant-filter').val();

    if (!date) { alert('Please select a date'); return; }
    if (!tenantId) { alert('Please select a tenant'); return; }

    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    loadDailyReport(date, tenantId);
  });

  // Export handler (reuses commercial export proxy)
  $('#daily-export-excel').on('click', function() {
    const date = $('#daily-report-date').val();
    const tenantId = $('#daily-tenant-filter').val();
    if (!date || !tenantId) { alert('Please select both date and tenant before exporting'); return; }
    let url = "{{ route('commercial.sales-report.export') }}";
    url += `?date=${encodeURIComponent(date)}&tenant_id=${encodeURIComponent(tenantId)}`;
    window.location.href = url;
  });

  // Clear on date change
  $('#daily-report-date-picker').on('change.datetimepicker', function(e) {
    const date = e.date ? e.date.format('YYYY-MM-DD') : moment().format('YYYY-MM-DD');
    $('#daily-report-date').val(date);
    $('#daily-date-generated').text((e.date || moment()).format('MMM DD YYYY'));
    $('#daily-report-tbody').html(`<tr><td colspan="5" class="text-center py-4 text-muted">Please select a date and tenant, then click "Load Report" to view the daily sales summary.</td></tr>`);
    $('#daily-total-gross, #daily-total-net').text('-');
    $('#daily-total-transactions, #daily-total-guests').text('-');
    $('#summary-gross, #summary-net, #summary-transactions, #summary-guests').text('-');
    $('#daily-export-excel').prop('disabled', true);
  });
});
</script>
@endpush
