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
    <div class="row mb-2 filter-row">
      <div class="col-md-12 d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center flex-wrap">
          <strong class="mr-2">Period Cover:</strong>
          <label class="mr-2 mb-0 align-self-center" for="week-date-from"><small>From</small></label>
          <div class="input-group input-group-sm date mr-2 mb-2 mb-md-0" id="week-date-from-picker" data-target-input="nearest" style="max-width:220px;">
            <input type="text" id="week-date-from" class="form-control form-control-sm datetimepicker-input" data-target="#week-date-from-picker" placeholder="YYYY-MM-DD" autocomplete="off" />
            <div class="input-group-append" data-target="#week-date-from-picker" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <label class="mr-2 mb-0 align-self-center" for="week-date-to"><small>To</small></label>
          <div class="input-group input-group-sm date mr-2 mb-2 mb-md-0" id="week-date-to-picker" data-target-input="nearest" style="max-width:220px;">
            <input type="text" id="week-date-to" class="form-control form-control-sm datetimepicker-input" data-target="#week-date-to-picker" placeholder="YYYY-MM-DD" autocomplete="off" />
            <div class="input-group-append" data-target="#week-date-to-picker" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <strong class="mr-2">Select Tenant:</strong>
          <select id="weekly-tenant-filter" class="form-control form-control-sm mr-3 mb-2 mb-md-0" style="max-width: 250px;">
            <option value="">-- All Tenants --</option>
          </select>
          <button id="weekly-load-report" class="btn btn-primary btn-sm mr-2 mb-2 mb-md-0 d-block d-md-inline-block"><i class="fa fa-search"></i> Load Report</button>
          <button id="weekly-export-excel" class="btn btn-success btn-sm mb-2 mb-md-0 d-block d-md-inline-block" disabled><i class="fa fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="text-right mt-2 mt-md-0">
          <strong>Date Generated:</strong> <span id="weekly-date-generated">Nov 28 2025</span>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm" id="weekly-sales-table" style="font-size: 12px;">
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
        <tbody id="weekly-report-tbody">
          <tr>
            <td colspan="13" class="text-center py-4 text-muted">Please select a date range and tenant, then click "Load Report" to view the weekday sales data.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="table-warning fw-bold">
            <td class="text-center">Total</td>
            <td id="weekly-total-gross" class="text-end">-</td>
            <td id="weekly-total-vatable" class="text-end">-</td>
            <td id="weekly-total-exempt" class="text-end">-</td>
            <td id="weekly-total-vat" class="text-end">-</td>
            <td id="weekly-total-sc" class="text-end">-</td>
            <td id="weekly-total-discount" class="text-end">-</td>
            <td id="weekly-total-net" class="text-end">-</td>
            <td id="weekly-total-cash" class="text-end">-</td>
            <td id="weekly-total-card" class="text-end">-</td>
            <td id="weekly-total-other" class="text-end">-</td>
            <td id="weekly-total-transactions" class="text-center">-</td>
            <td id="weekly-total-guests" class="text-center">-</td>
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
  // initialize date inputs with sensible defaults (this week's Monday-Friday by default)
  const now = moment();
  const startOfWeek = now.clone().startOf('week');
  const endOfWeek = now.clone().endOf('week');
  // init datetimepickers
  try {
    $('#week-date-from-picker').datetimepicker({ format: 'YYYY-MM-DD', defaultDate: startOfWeek, icons: { time: 'far fa-clock', date: 'fa fa-calendar' } });
    $('#week-date-to-picker').datetimepicker({ format: 'YYYY-MM-DD', defaultDate: endOfWeek, icons: { time: 'far fa-clock', date: 'fa fa-calendar' } });
  } catch (err) {
    // fallback to plain inputs
    $('#week-date-from').val(startOfWeek.format('YYYY-MM-DD'));
    $('#week-date-to').val(endOfWeek.format('YYYY-MM-DD'));
  }
  $('#weekly-date-generated').text(now.format('MMM DD YYYY'));

  // Load tenants
  function loadTenants() {
    $.ajax({ url: '{{ route('commercial.sales-report.tenants') }}', success: function(tenants) {
      const dropdown = $('#weekly-tenant-filter');
      dropdown.empty().append('<option value="">-- All Tenants --</option>');
      tenants.forEach(t => dropdown.append(`<option value="${t.id}">${t.trade_name} (${t.customer_code})</option>`));
    }, error: function() { console.error('Failed to load tenants'); } });
  }
  loadTenants();

  function renderEmpty() {
    $('#weekly-report-tbody').html(`<tr><td colspan="13" class="text-center py-4 text-muted">No data available for the selected range/tenant.</td></tr>`);
    ['gross','vatable','exempt','vat','sc','discount','net','cash','card','other','transactions','guests'].forEach(k => {
      $(`#weekly-total-${k}`).text('-');
    });
    $('#weekly-export-excel').prop('disabled', true);
  }

  // Expose loadWeekly globally so other views can call it if needed.
  window.loadWeekly = function(from, to, tenantId) {
    if (!from || !to) { renderEmpty(); return; }
    $.ajax({
      url: '{{ route('commercial.sales-report.tsms-proxy.transactions.weekday') }}',
      data: { date_from: from, date_to: to, tenant_id: tenantId },
      success: function(resp) {
        if (!resp || !resp.days || resp.days.length === 0) { renderEmpty(); return; }

        const tbody = $('#weekly-report-tbody');
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

        $('#weekly-total-gross').text(totals.gross.toFixed(2));
        $('#weekly-total-vatable').text(totals.vatable.toFixed(2));
        $('#weekly-total-exempt').text(totals.exempt.toFixed(2));
        $('#weekly-total-vat').text(totals.vat.toFixed(2));
        $('#weekly-total-sc').text(totals.sc.toFixed(2));
        $('#weekly-total-discount').text(totals.discount.toFixed(2));
        $('#weekly-total-net').text(totals.net.toFixed(2));
        $('#weekly-total-cash').text(totals.cash.toFixed(2));
        $('#weekly-total-card').text(totals.card.toFixed(2));
        $('#weekly-total-other').text(totals.other.toFixed(2));
        $('#weekly-total-transactions').text(Math.round(totals.transactions));
        $('#weekly-total-guests').text(Math.round(totals.guests));

        $('#weekly-export-excel').prop('disabled', false);
      },
      error: function() { renderEmpty(); }
    });
  };

  $('#weekly-load-report').on('click', function() {
    const from = $('#week-date-from').val();
    const to = $('#week-date-to').val();
    const tenantId = $('#weekly-tenant-filter').val();
    if (!from || !to) { alert('Please select both from and to dates'); return; }
    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    window.loadWeekly(from, to, tenantId);
    $(this).prop('disabled', false).html('<i class="fa fa-search"></i> Load Report');
  });

  // Export handler
  $('#weekly-export-excel').on('click', function() {
    const from = $('#week-date-from').val();
    const to = $('#week-date-to').val();
    const tenantId = $('#weekly-tenant-filter').val();
    if (!from || !to) { alert('Please select both from and to dates'); return; }
    let url = "{{ route('commercial.sales-report.export') }}";
    url += `?date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}&tenant_id=${encodeURIComponent(tenantId)}`;
    window.location.href = url;
  });

});
</script>
@endpush
