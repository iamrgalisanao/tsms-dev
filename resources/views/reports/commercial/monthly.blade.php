@extends('layouts.master')
@section('title', 'Monthly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Monthly Sales Report</h3>
  </div>
  <div class="card-body">
    <div class="row mb-2 filter-row">
      <div class="col-md-12 d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center flex-wrap">
          <strong class="mr-2">Period Cover:</strong>
          <label class="mr-2 mb-0 align-self-center" for="month-picker"><small>Month</small></label>
          <div class="input-group input-group-sm date mr-2 mb-2 mb-md-0" id="month-picker-wrapper" data-target-input="nearest" style="max-width:220px;">
            <input type="text" id="month-picker" class="form-control form-control-sm datetimepicker-input" data-target="#month-picker-wrapper" placeholder="YYYY-MM" autocomplete="off" />
            <div class="input-group-append" data-target="#month-picker-wrapper" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <strong class="mr-2">Select Tenant:</strong>
          <select id="monthly-tenant-filter" class="form-control form-control-sm mr-3 mb-2 mb-md-0" style="max-width: 250px;">
            <option value="">-- All Tenants --</option>
          </select>
          <button id="monthly-load-report" class="btn btn-primary btn-sm mr-2 mb-2 mb-md-0 d-block d-md-inline-block"><i class="fa fa-search"></i> Load Report</button>
          <button id="monthly-export-excel" class="btn btn-success btn-sm mb-2 mb-md-0 d-block d-md-inline-block" disabled><i class="fa fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="text-right mt-2 mt-md-0">
          <strong>Date Generated:</strong> <span id="monthly-date-generated">{{ now()->format('M d Y') }}</span>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm" id="monthly-sales-table" style="font-size: 12px;">
        <thead>
          <tr class="table-primary">
            <th class="text-center">Date</th>
            <th class="text-end">Gross Sales</th>
            <th class="text-end">Vatable Sales</th>
            <th class="text-end">Non-Vatable Sales</th>
            <th class="text-end">VAT Amount</th>
            <th class="text-end">SC/PWD Discount</th>
            <th class="text-end">Regular Discount</th>
            <th class="text-end">Net Sales</th>
            <th class="text-end">Cash</th>
            <th class="text-end">Card</th>
            <th class="text-end">Other</th>
            <th class="text-center">Transactions</th>
            <th class="text-center">Guests</th>
          </tr>
        </thead>
        <tbody id="monthly-report-tbody">
          <tr>
            <td colspan="13" class="text-center py-4 text-muted">Please select a month and tenant, then click "Load Report" to view the monthly sales data.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="table-warning fw-bold">
            <td class="text-center">Total</td>
            <td id="monthly-total-gross" class="text-end">-</td>
            <td id="monthly-total-vatable" class="text-end">-</td>
            <td id="monthly-total-exempt" class="text-end">-</td>
            <td id="monthly-total-vat" class="text-end">-</td>
            <td id="monthly-total-sc" class="text-end">-</td>
            <td id="monthly-total-discount" class="text-end">-</td>
            <td id="monthly-total-net" class="text-end">-</td>
            <td id="monthly-total-cash" class="text-end">-</td>
            <td id="monthly-total-card" class="text-end">-</td>
            <td id="monthly-total-other" class="text-end">-</td>
            <td id="monthly-total-transactions" class="text-center">-</td>
            <td id="monthly-total-guests" class="text-center">-</td>
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
  const now = moment();
  const startOfMonth = now.clone().startOf('month');
  try {
    // month-only picker: show months view
    $('#month-picker-wrapper').datetimepicker({ format: 'YYYY-MM', defaultDate: startOfMonth, viewMode: 'months', icons: { time: 'far fa-clock', date: 'fa fa-calendar' } });
    $('#month-picker').val(startOfMonth.format('YYYY-MM'));
  } catch (err) {
    $('#month-picker').val(startOfMonth.format('YYYY-MM'));
  }
  $('#monthly-date-generated').text(now.format('MMM DD YYYY'));

  function loadTenants() {
    $.ajax({ url: '{{ route('commercial.sales-report.tenants') }}', success: function(tenants) {
      const dropdown = $('#monthly-tenant-filter');
      dropdown.empty().append('<option value="">-- All Tenants --</option>');
      tenants.forEach(t => dropdown.append(`<option value="${t.id}">${t.trade_name} (${t.customer_code})</option>`));
    }, error: function() { console.error('Failed to load tenants'); } });
  }
  loadTenants();

  function renderEmpty() {
    $('#monthly-report-tbody').html(`<tr><td colspan="13" class="text-center py-4 text-muted">No data available for the selected month/tenant.</td></tr>`);
    ['gross','vatable','exempt','vat','sc','discount','net','cash','card','other','transactions','guests'].forEach(k => {
      $(`#monthly-total-${k}`).text('-');
    });
    $('#monthly-export-excel').prop('disabled', true);
  }

  function loadMonthly(fromMonth, tenantId) {
    if (!fromMonth) { renderEmpty(); return; }
    // compute month bounds
    const m = moment(fromMonth, 'YYYY-MM');
    if (!m.isValid()) { renderEmpty(); return; }
    const from = m.clone().startOf('month').format('YYYY-MM-DD');
    const to = m.clone().endOf('month').format('YYYY-MM-DD');
    $.ajax({
      url: '{{ route('commercial.sales-report.tsms-proxy.transactions.monthly') }}',
      data: { date_from: from, date_to: to, tenant_id: tenantId },
      success: function(resp) {
        if (!resp || !resp.days || resp.days.length === 0) { renderEmpty(); return; }

        const tbody = $('#monthly-report-tbody');
        tbody.empty();
        let totals = { gross:0, vatable:0, exempt:0, vat:0, sc:0, discount:0, net:0, cash:0, card:0, other:0, transactions:0, guests:0 };

        resp.days.forEach(d => {
          tbody.append(`<tr>
            <td class="text-center">${d.date}</td>
            <td class="text-end">${(Number(d.gross_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.vatable_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.vat_exempt_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.vat_amount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.sc_pwd_discount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.regular_discount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.net_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.cash_payment)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.card_payment)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(d.other_tender)||0).toFixed(2)}</td>
            <td class="text-center">${Math.round(Number(d.transaction_count)||0)}</td>
            <td class="text-center">${Math.round(Number(d.guest_count)||0)}</td>
          </tr>`);

          totals.gross += Number(d.gross_sales||0);
          totals.vatable += Number(d.vatable_sales||0);
          totals.exempt += Number(d.vat_exempt_sales||0);
          totals.vat += Number(d.vat_amount||0);
          totals.sc += Number(d.sc_pwd_discount||0);
          totals.discount += Number(d.regular_discount||0);
          totals.net += Number(d.net_sales||0);
          totals.cash += Number(d.cash_payment||0);
          totals.card += Number(d.card_payment||0);
          totals.other += Number(d.other_tender||0);
          totals.transactions += Number(d.transaction_count||0);
          totals.guests += Number(d.guest_count||0);
        });

        $('#monthly-total-gross').text(totals.gross.toFixed(2));
        $('#monthly-total-vatable').text(totals.vatable.toFixed(2));
        $('#monthly-total-exempt').text(totals.exempt.toFixed(2));
        $('#monthly-total-vat').text(totals.vat.toFixed(2));
        $('#monthly-total-sc').text(totals.sc.toFixed(2));
        $('#monthly-total-discount').text(totals.discount.toFixed(2));
        $('#monthly-total-net').text(totals.net.toFixed(2));
        $('#monthly-total-cash').text(totals.cash.toFixed(2));
        $('#monthly-total-card').text(totals.card.toFixed(2));
        $('#monthly-total-other').text(totals.other.toFixed(2));
        $('#monthly-total-transactions').text(Math.round(totals.transactions));
        $('#monthly-total-guests').text(Math.round(totals.guests));

        $('#monthly-export-excel').prop('disabled', false);
      },
      error: function() { renderEmpty(); }
    });
  }

  $('#monthly-load-report').on('click', function() {
    const month = $('#month-picker').val();
    const tenantId = $('#monthly-tenant-filter').val();
    if (!month) { alert('Please select a month'); return; }
    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    loadMonthly(month, tenantId);
    $(this).prop('disabled', false).html('<i class="fa fa-search"></i> Load Report');
  });

  $('#monthly-export-excel').on('click', function() {
  const month = $('#month-picker').val();
  const m = moment(month, 'YYYY-MM');
  if (!m.isValid()) { alert('Please select a month'); return; }
  const from = m.clone().startOf('month').format('YYYY-MM-DD');
  const to = m.clone().endOf('month').format('YYYY-MM-DD');
    const tenantId = $('#monthly-tenant-filter').val();
    if (!from || !to) { alert('Please select both from and to dates'); return; }
    let url = "{{ route('commercial.sales-report.export') }}";
    url += `?date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}&tenant_id=${encodeURIComponent(tenantId)}`;
    window.location.href = url;
  });

});
</script>
@endpush
@extends('layouts.master')
@section('title', 'Monthly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
</style>
@endpush
@section('content')
<div class="card report-card">
  <div class="card-header">
    <h3 class="card-title">Monthly Commercial Report</h3>
  </div>
  <div class="card-body">
    <div class="report-placeholder">Monthly report UI goes here. Use month picker and include export/print actions.</div>
  </div>
</div>
@endsection
