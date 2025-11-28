@extends('layouts.master')
@section('title', 'Yearly Commercial Report')
@push('styles')
<style>
  .report-card { margin: 1rem 0; }
  .report-placeholder { padding: 2rem; text-align: center; color: #6b7280; }
  /* Loading overlay for report tables */
  .report-loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.8);
    z-index: 20;
  }
  .report-loading-overlay.hidden { display: none; }
</style>
@endpush
@section('content')
<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Yearly Sales Report</h3>
  </div>
  <div class="card-body">
    <div class="row mb-2 filter-row">
      <div class="col-md-12 d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center flex-wrap">
          <strong class="mr-2">Year:</strong>
          <div class="input-group input-group-sm date mr-2 mb-2 mb-md-0" id="year-picker-wrapper" data-target-input="nearest" style="max-width:160px;">
            <input type="text" id="year-picker" class="form-control form-control-sm datetimepicker-input" data-target="#year-picker-wrapper" placeholder="YYYY" autocomplete="off" />
            <div class="input-group-append" data-target="#year-picker-wrapper" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <strong class="mr-2">Select Tenant:</strong>
          <select id="yearly-tenant-filter" class="form-control form-control-sm mr-3 mb-2 mb-md-0" style="max-width: 250px;">
            <option value="">-- All Tenants --</option>
          </select>
          <button id="yearly-load-report" class="btn btn-primary btn-sm mr-2 mb-2 mb-md-0 d-block d-md-inline-block"><i class="fa fa-search"></i> Load Report</button>
          <button id="yearly-export-excel" class="btn btn-success btn-sm mb-2 mb-md-0 d-block d-md-inline-block" disabled><i class="fa fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="text-right mt-2 mt-md-0">
          <strong>Date Generated:</strong> <span id="yearly-date-generated">{{ now()->format('M d Y') }}</span>
        </div>
      </div>
    </div>

    <div class="table-responsive" style="position: relative;">
      <div id="yearly-loading-overlay" class="report-loading-overlay hidden">
        <div class="text-center">
          <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
          <div class="mt-2">Loading yearly report…</div>
        </div>
      </div>
      <table class="table table-bordered table-sm" id="yearly-sales-table" style="font-size: 12px;">
        <thead>
          <tr class="table-primary">
            <th class="text-center">Month</th>
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
        <tbody id="yearly-report-tbody">
          <tr>
            <td colspan="13" class="text-center py-4 text-muted">Please select a year and tenant, then click "Load Report" to view the yearly sales data.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="table-warning fw-bold">
            <td class="text-center">Total</td>
            <td id="yearly-total-gross" class="text-end">-</td>
            <td id="yearly-total-vatable" class="text-end">-</td>
            <td id="yearly-total-exempt" class="text-end">-</td>
            <td id="yearly-total-vat" class="text-end">-</td>
            <td id="yearly-total-sc" class="text-end">-</td>
            <td id="yearly-total-discount" class="text-end">-</td>
            <td id="yearly-total-net" class="text-end">-</td>
            <td id="yearly-total-cash" class="text-end">-</td>
            <td id="yearly-total-card" class="text-end">-</td>
            <td id="yearly-total-other" class="text-end">-</td>
            <td id="yearly-total-transactions" class="text-center">-</td>
            <td id="yearly-total-guests" class="text-center">-</td>
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
  try {
    // warn if tempusdominus library was loaded more than once
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.datetimepicker) {
      const tempusScripts = $('script[src*="tempusdominus"]').map((i,el)=>el.src).get();
      if (tempusScripts.length > 1) {
        console.warn('Multiple Tempus Dominus script tags detected:', tempusScripts);
      }

      if (!$('#year-picker-wrapper').data('datetimepicker')) {
        $('#year-picker-wrapper').datetimepicker({ format: 'YYYY', defaultDate: now, viewMode: 'years', icons: { time: 'far fa-clock', date: 'fa fa-calendar' } });
      }
      $('#year-picker').val(now.format('YYYY'));
    } else {
      console.warn('datetimepicker plugin not detected when initializing year picker');
      $('#year-picker').val(now.format('YYYY'));
    }
  } catch (err) {
    console.debug('Year picker init error', err);
    $('#year-picker').val(now.format('YYYY'));
  }
  $('#yearly-date-generated').text(now.format('MMM DD YYYY'));

  function loadTenants() {
    $.ajax({ url: '{{ route('commercial.sales-report.tenants') }}', success: function(tenants) {
      const dropdown = $('#yearly-tenant-filter');
      dropdown.empty().append('<option value="">-- All Tenants --</option>');
      tenants.forEach(t => dropdown.append(`<option value="${t.id}">${t.trade_name} (${t.customer_code})</option>`));
    }, error: function() { console.error('Failed to load tenants'); } });
  }
  loadTenants();

  // Capture-phase guard: ensure any clicked toggle has an initialized instance
  document.addEventListener('click', function (ev) {
    try {
      const toggle = ev.target.closest && ev.target.closest('[data-toggle="datetimepicker"]');
      if (!toggle) return;
      const selector = toggle.getAttribute('data-target') || toggle.dataset.target;
      if (!selector) return;
      const target = document.querySelector(selector);
      if (!target) return;
      if (!window.jQuery) return;
      const $target = window.jQuery(target);
      if (!$target.data('datetimepicker') && window.jQuery.fn && window.jQuery.fn.datetimepicker) {
        $target.datetimepicker({ format: 'YYYY', defaultDate: now, viewMode: 'years', icons: { time: 'far fa-clock', date: 'fa fa-calendar' } });
        console.info('Initialized missing datetimepicker instance for', selector);
      }
    } catch (err) {
      console.debug('yearly datetimepicker capture-init error', err);
    }
  }, true);

  function renderEmpty() {
    $('#yearly-report-tbody').html(`<tr><td colspan="13" class="text-center py-4 text-muted">No data available for the selected year/tenant.</td></tr>`);
    ['gross','vatable','exempt','vat','sc','discount','net','cash','card','other','transactions','guests'].forEach(k => {
      $(`#yearly-total-${k}`).text('-');
    });
    $('#yearly-export-excel').prop('disabled', true);
  }

  function loadYearly(year, tenantId) {
    if (!year) { renderEmpty(); return; }
    const from = moment(year + '-01-01').format('YYYY-MM-DD');
    const to = moment(year + '-12-31').format('YYYY-MM-DD');
    return $.ajax({
      url: '{{ route('commercial.sales-report.tsms-proxy.transactions.yearly') }}',
      data: { date_from: from, date_to: to, tenant_id: tenantId },
      success: function(resp) {
        if (!resp || !resp.months || resp.months.length === 0) { renderEmpty(); return; }

        const tbody = $('#yearly-report-tbody');
        tbody.empty();
        let totals = { gross:0, vatable:0, exempt:0, vat:0, sc:0, discount:0, net:0, cash:0, card:0, other:0, transactions:0, guests:0 };

        resp.months.forEach(m => {
          tbody.append(`<tr>
            <td class="text-center">${m.month}</td>
            <td class="text-end">${(Number(m.gross_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.vatable_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.vat_exempt_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.vat_amount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.sc_pwd_discount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.regular_discount)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.net_sales)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.cash_payment)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.card_payment)||0).toFixed(2)}</td>
            <td class="text-end">${(Number(m.other_tender)||0).toFixed(2)}</td>
            <td class="text-center">${Math.round(Number(m.transaction_count)||0)}</td>
            <td class="text-center">${Math.round(Number(m.guest_count)||0)}</td>
          </tr>`);

          totals.gross += Number(m.gross_sales||0);
          totals.vatable += Number(m.vatable_sales||0);
          totals.exempt += Number(m.vat_exempt_sales||0);
          totals.vat += Number(m.vat_amount||0);
          totals.sc += Number(m.sc_pwd_discount||0);
          totals.discount += Number(m.regular_discount||0);
          totals.net += Number(m.net_sales||0);
          totals.cash += Number(m.cash_payment||0);
          totals.card += Number(m.card_payment||0);
          totals.other += Number(m.other_tender||0);
          totals.transactions += Number(m.transaction_count||0);
          totals.guests += Number(m.guest_count||0);
        });

        $('#yearly-total-gross').text(totals.gross.toFixed(2));
        $('#yearly-total-vatable').text(totals.vatable.toFixed(2));
        $('#yearly-total-exempt').text(totals.exempt.toFixed(2));
        $('#yearly-total-vat').text(totals.vat.toFixed(2));
        $('#yearly-total-sc').text(totals.sc.toFixed(2));
        $('#yearly-total-discount').text(totals.discount.toFixed(2));
        $('#yearly-total-net').text(totals.net.toFixed(2));
        $('#yearly-total-cash').text(totals.cash.toFixed(2));
        $('#yearly-total-card').text(totals.card.toFixed(2));
        $('#yearly-total-other').text(totals.other.toFixed(2));
        $('#yearly-total-transactions').text(Math.round(totals.transactions));
        $('#yearly-total-guests').text(Math.round(totals.guests));

        $('#yearly-export-excel').prop('disabled', false);
      },
      error: function() { renderEmpty(); }
    });
  }

  $('#yearly-load-report').on('click', function() {
    const $btn = $(this);
    const year = $('#year-picker').val();
    const tenantId = $('#yearly-tenant-filter').val();
    if (!year) { alert('Please select a year'); return; }

    // show spinner overlay and set button state
    $('#yearly-loading-overlay').removeClass('hidden');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');

    // call loader and hide spinner when request finishes
    const req = loadYearly(year, tenantId);
    if (req && req.always) {
      req.always(function() {
        $('#yearly-loading-overlay').addClass('hidden');
        $btn.prop('disabled', false).html('<i class="fa fa-search"></i> Load Report');
      });
    } else {
      // fallback: hide overlay after a short delay
      setTimeout(function() {
        $('#yearly-loading-overlay').addClass('hidden');
        $btn.prop('disabled', false).html('<i class="fa fa-search"></i> Load Report');
      }, 800);
    }
  });

  $('#yearly-export-excel').on('click', function() {
    const year = $('#year-picker').val();
    const m = moment(year, 'YYYY');
    if (!m.isValid()) { alert('Please select a year'); return; }
    const from = m.clone().startOf('year').format('YYYY-MM-DD');
    const to = m.clone().endOf('year').format('YYYY-MM-DD');
    const tenantId = $('#yearly-tenant-filter').val();
    let url = "{{ route('commercial.sales-report.export') }}";
    url += `?date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}&tenant_id=${encodeURIComponent(tenantId)}`;
    window.location.href = url;
  });

});
</script>
@endpush
