@extends('layouts.master')

@section('title', 'Hourly Sales / Average Hourly Sales Report')

@section('content')

<style>
/* Date picker styling - Enhanced for hourly reports */
/* 1) consistent sizing */
#report-date,
#report-date-picker .input-group-text {
  box-sizing: border-box;
}

/* 2) stable widths */
#period-cover {
  min-width: 180px;
}
#tenant-filter {
  min-width: 250px;
}

/* 3) switch to outline focus (no width change) */
#report-date {
  border: 2px solid #007bff;
  border-radius: 4px 0 0 4px;
  cursor: pointer;
  transition: border-color .2s;
}
#report-date:hover {
  border-color: #0056b3;
}
#report-date:focus {
  outline: .2rem solid rgba(0,123,255,0.25);
  border-color: #0056b3;
}

#report-date-picker .input-group-text {
  background-color: #007bff;
  color: #fff;
  border: 2px solid #007bff;
  border-left: none;
  border-radius: 0 4px 4px 0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background-color .2s, border-color .2s;
}
#report-date-picker .input-group-text:hover {
  background-color: #0056b3;
  border-color: #0056b3;
}

/* Fix date picker dropdown overlap issues */
#report-date-picker,
.input-group.date {
  position: relative;
}
.bootstrap-datetimepicker-widget,
.tempus-dominus-widget,
.datetimepicker-widget {
  position: absolute !important;
  top: 100% !important;
  left: 0 !important;
  z-index: 1050 !important;
}

.card-body {
  overflow: visible !important;
}

.filter-row {
  overflow: visible !important;
  position: relative;
  z-index: 1000;
}
</style>

<div class="card">
  <div class="card-header bg-primary">
    <h3 class="card-title text-white">Hourly Sales / Average Hourly Sales Report</h3>
  </div>
  <div class="card-body">
    <div class="row mb-2 filter-row">
      <div class="col-md-12 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <strong class="mr-2">Period Cover:</strong>
          <span id="period-cover" class="mr-3 px-2 py-1 bg-light border rounded">MMM DD YYYY to MMM DD YYYY</span>
          <div class="input-group input-group-sm date mr-3" id="report-date-picker" data-target-input="nearest" style="max-width: 160px; min-width: 140px;">
            <input type="text" id="report-date" class="form-control form-control-sm datetimepicker-input" data-target="#report-date-picker" placeholder="Select date" autocomplete="off"/>
            <div class="input-group-append" data-target="#report-date-picker" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
          <strong class="mr-2">Select Tenant:</strong>
          <select id="tenant-filter" class="form-control form-control-sm mr-3" style="max-width: 250px;">
            <option value="">-- Select Tenant --</option>
          </select>
          <button id="load-report" class="btn btn-primary btn-sm mr-2"><i class="fa fa-search"></i> Load Report</button>
          <button id="export-excel" class="btn btn-success btn-sm" disabled><i class="fa fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="text-right">
          <strong>Date Generated:</strong> <span id="date-generated">MMM DD YYYY</span>
        </div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-sm" id="daily-sales-table" style="font-size: 11px;">
        <thead>
          <tr class="table-primary">
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 80px;">Customer Code</th>
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 150px;">Tenant Name</th>
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 80px;">Location</th>
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 60px;">Zone</th>
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 90px;">Date / Period</th>
            <th class="text-center align-middle" style="background-color: #E6F3FF; min-width: 60px;">Hours</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Gross Sales</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Variable Sales</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Non-Vatable Sales</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">12% VAT / Tax Amount</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">SC / PWD Discount</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Regular Discount</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 70px;">Void</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 70px;">Return</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Net Sales</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Cash Payment</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Card Payment</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 90px;">Other Tender</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 120px;">Net Sales Subject to Percentage Rent</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 80px;">Transaction Count</th>
            <th class="text-center align-middle" style="background-color: #FFE6E6; min-width: 80px;">Guest Count</th>
          </tr>
        </thead>
        <tbody id="report-tbody">
          <tr>
            <td colspan="21" class="text-center py-4 text-muted">
              <i class="fas fa-info-circle me-2"></i>
              Please select a date and tenant, then click "Load Report" to view the hourly sales data.
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="table-warning fw-bold">
            <td colspan="6" class="text-center fw-bold">Total</td>
            <td colspan="15" id="total-row-container" class="p-0">
              <table class="w-100 mb-0">
                <tr id="total-row"></tr>
              </table>
            </td>
          </tr>
          <tr class="table-info fw-bold">
            <td colspan="6" class="text-center fw-bold">Average</td>
            <td colspan="15" id="average-row-container" class="p-0">
              <table class="w-100 mb-0">
                <tr id="average-row"></tr>
              </table>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="row mt-4">
      <div class="col-md-4 text-center">
        <div class="border-top pt-2 mt-5">
          <strong>Ms. Bernadette Andiano</strong><br>
          <small>Lease Operation Associate</small><br>
          <small><strong>Prepared by:</strong></small>
        </div>
      </div>
      <div class="col-md-4 text-center">
        <div class="border-top pt-2 mt-5">
          <strong>Czarina Toca</strong><br>
          <small>Commercial & Ancillary Business Manager</small><br>
          <small><strong>Checked by:</strong></small>
        </div>
      </div>
      <div class="col-md-4 text-center">
        <div class="border-top pt-2 mt-5">
          <strong>Ruth Morales</strong><br>
          <small>Commercial Head</small><br>
          <small><strong>Approved by:</strong></small>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<!-- Required: Bootstrap 4 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="{{ asset('plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css') }}">
<style>
#report-date-picker .form-control-sm { background: #fff; }
#report-date-picker .input-group-text { background: #fff; }

/* Print styles */
@media print {
  .card-header {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  
  table {
    font-size: 9px !important;
  }
  
  .table-bordered th,
  .table-bordered td {
    border: 1px solid #000 !important;
  }
  
  .table-primary th {
    background-color: #E6F3FF !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  
  th[style*="background-color: #FFE6E6"] {
    background-color: #FFE6E6 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
}

/* Responsive table styling */
.table-responsive {
  max-height: 70vh;
  overflow-y: auto;
}

.table th {
  position: sticky;
  top: 0;
  z-index: 10;
}

/* Signature section styling */
.border-top {
  border-top: 2px solid #000 !important;
}

/* Total/Average row alignment fix */
#total-row-container table,
#average-row-container table {
  table-layout: fixed;
  border: none;
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}

#total-row td,
#average-row td {
  border: none;
  padding: 8px 4px;
  font-weight: bold;
  text-align: left !important;
  vertical-align: middle;
  box-sizing: border-box;
  white-space: nowrap;
  overflow: hidden;
}

/* Ensure exact width matching with header columns */
#total-row td:nth-child(1),   /* gross_sales */
#total-row td:nth-child(2),   /* vatable_sales */  
#total-row td:nth-child(3),   /* vat_exempt_sales */
#total-row td:nth-child(4),   /* vat_amount */
#total-row td:nth-child(5),   /* sc_pwd_discount */
#total-row td:nth-child(6),   /* regular_discount */
#total-row td:nth-child(9),   /* net_sales */
#total-row td:nth-child(10),  /* cash_payment */
#total-row td:nth-child(11),  /* card_payment */
#total-row td:nth-child(12),  /* other_tender */
#average-row td:nth-child(1),
#average-row td:nth-child(2),
#average-row td:nth-child(3),
#average-row td:nth-child(4),
#average-row td:nth-child(5),
#average-row td:nth-child(6),
#average-row td:nth-child(9),
#average-row td:nth-child(10),
#average-row td:nth-child(11),
#average-row td:nth-child(12) {
  width: 90px;
  min-width: 90px;
  max-width: 90px;
}

#total-row td:nth-child(7),   /* void */
#total-row td:nth-child(8),   /* return */
#average-row td:nth-child(7),
#average-row td:nth-child(8) {
  width: 70px;
  min-width: 70px;
  max-width: 70px;
}

#total-row td:nth-child(13),  /* net_sales_percentage_rent */
#average-row td:nth-child(13) {
  width: 120px;
  min-width: 120px;
  max-width: 120px;
}

#total-row td:nth-child(14),  /* transaction_count */
#total-row td:nth-child(15),  /* guest_count */
#average-row td:nth-child(14),
#average-row td:nth-child(15) {
  width: 80px;
  min-width: 80px;
  max-width: 80px;
}
</style>
@endpush

@push('scripts')
<!-- Note: core libs (jQuery, Bootstrap, Moment, Tempus Dominus) are loaded in layouts/master.blade.php. -->
<script>
$(function() {
  // Debug: log if datepicker plugin is loaded
  if (!$.fn.datetimepicker) {
    console.error('Tempus Dominus datetimepicker is not loaded!');
  }
  // Initialize AdminLTE3 datepicker
  $('#report-date-picker').datetimepicker({
    format: 'YYYY-MM-DD',
    defaultDate: moment(),
    icons: { time: 'far fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check', clear: 'fa fa-trash', close: 'fa fa-times' }
  });

  // Defensive checks: ensure the plugin instance exists (protects against double-includes
  // or race conditions where the global event handler fires before initialization).
  (function ensureDatepickerInstance() {
    try {
      // warn if tempusdominus library was loaded more than once
      const tempusScripts = $('script[src*="tempusdominus"]').map((i,el)=>el.src).get();
      if (tempusScripts.length > 1) {
        console.warn('Multiple Tempus Dominus script tags detected:', tempusScripts);
      }

      const inst = $('#report-date-picker').data('datetimepicker');
      if (!inst) {
        console.warn('Datetimepicker instance missing on #report-date-picker — attempting re-init');
        $('#report-date-picker').datetimepicker({
          format: 'YYYY-MM-DD',
          defaultDate: moment(),
          icons: { time: 'far fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check', clear: 'fa fa-trash', close: 'fa fa-times' }
        });
      }
    } catch (err) {
      // Non-fatal — log for diagnostics
      console.error('Error during defensive datetimepicker init:', err);
    }
  })();

  // Capture-phase handler: ensure any clicked toggle has an instance before
  // Tempus Dominus's jQuery handlers run (these run in bubbling phase and
  // may try to access an undefined instance). Using a native capture listener
  // guarantees this runs first and prevents `_options` undefined errors.
  document.addEventListener('click', function (ev) {
    try {
      const toggle = ev.target.closest && ev.target.closest('[data-toggle="datetimepicker"]');
      if (!toggle) return;

      // read data-target (can be selector like '#report-date-picker')
      const selector = toggle.getAttribute('data-target') || toggle.dataset.target;
      if (!selector) return;

      const target = document.querySelector(selector);
      if (!target) return;

      // If the plugin instance is missing, initialize it safely
      if (!window.jQuery) return;
      const $target = window.jQuery(target);
      if (!$target.data('datetimepicker')) {
        // Use the same options used elsewhere on the page
        $target.datetimepicker({
          format: 'YYYY-MM-DD',
          defaultDate: moment(),
          icons: { time: 'far fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check', clear: 'fa fa-trash', close: 'fa fa-times' }
        });
        console.info('Initialized missing datetimepicker instance for', selector);
      }
    } catch (err) {
      // non-fatal — log for diagnostics
      console.debug('datetimepicker capture-init error', err);
    }
  }, true);

  // Set initial value
  $('#report-date').val(moment().format('YYYY-MM-DD'));

  // Set period cover and date generated
  function setPeriodAndGenerated(date) {
    const formatted = moment(date).format('MMM DD YYYY');
    $('#date-generated').text(formatted);
    $('#period-cover').text(`${formatted} to ${formatted}`);
  }
  setPeriodAndGenerated(moment());

  // Load tenants for dropdown
  function loadTenants() {
    $.ajax({
      url: '{{ route('commercial.sales-report.tenants') }}',
      success: function(tenants) {
        const dropdown = $('#tenant-filter');
        dropdown.empty().append('<option value="">-- Select Tenant --</option>');
        tenants.forEach(tenant => {
          dropdown.append(`<option value="${tenant.id}">${tenant.trade_name} (${tenant.customer_code})</option>`);
        });
      },
      error: function() {
        console.error('Failed to load tenants');
      }
    });
  }

  // Load tenants on page load
  loadTenants();

  // Fetch and render server-side hourly aggregated table data
  function loadHourlySales(date = null, tenantId = null) {
    if (!date || !tenantId) {
      const tbody = $('#report-tbody');
      tbody.html(`
        <tr>
          <td colspan="21" class="text-center py-4 text-muted">
            <i class="fas fa-info-circle me-2"></i>
            Please select a date and tenant to view the hourly sales report.
          </td>
        </tr>
      `);
      $('#total-row, #average-row').empty();
      $('#export-excel').prop('disabled', true);
      return;
    }

    $.ajax({
      url: '{{ route('commercial.sales-report.tsms-proxy.transactions.hourly') }}',
      data: { date, tenant_id: tenantId },
      success: function(resp) {
        const tbody = $('#report-tbody');
        tbody.empty();
        let totals = {};

        // Build a map of hour -> row for quick lookup
        const rowsByHour = {};
        resp.data.forEach(r => {
          rowsByHour[r.hour] = r;
        });

        // Render 24 hours
        for (let hour = 0; hour < 24; hour++) {
          const hourFormatted = String(hour).padStart(2, '0') + ':00';
          const row = rowsByHour[hourFormatted] || null;

          if (!row) {
            // empty row
            let tr = '<tr>';
            tr += '<td class="text-center">-</td>'; // Customer code
            tr += '<td class="text-left">-</td>'; // Tenant name
            tr += '<td class="text-center">-</td>'; // Location
            tr += '<td class="text-center">-</td>'; // Zone
            tr += '<td class="text-center">-</td>'; // Date
            tr += `<td class="text-center">${hourFormatted}</td>`; // Hour
            // Add 15 empty financial columns
            for (let i = 0; i < 15; i++) {
              tr += '<td class="text-end">-</td>';
            }
            tr += '</tr>';
            tbody.append(tr);
            continue;
          }

          // Render populated row
          const keysOrder = [
            'customer_code','tenant_name','location','zone','sales_date','hour',
            'gross_sales','vatable_sales','vat_exempt_sales','vat_amount',
            'sc_pwd_discount','regular_discount','void','return','net_sales',
            'cash_payment','card_payment','other_tender','net_sales_percentage_rent',
            'transaction_count','guest_count'
          ];

          let tr = '<tr>';
          keysOrder.forEach((k, index) => {
            let value = row[k];
            let cellClass = '';
            if (index === 0 || index === 2 || index === 3 || index === 4 || index === 5) {
              cellClass = 'text-center';
            } else if (index === 1) {
              cellClass = 'text-left';
            } else if (index >= 19) {
              cellClass = 'text-center';
            } else {
              cellClass = 'text-end';
            }

            if (value === undefined || value === null || value === '') {
              tr += `<td class="${cellClass}">-</td>`;
            } else if (!isNaN(Number(value)) && index >= 6) {
              // numeric
              const rounded = (index === 19 || index === 20) ? String(Math.round(Number(value))) : Number(value).toFixed(2);
              tr += `<td class="${cellClass}">${rounded}</td>`;

              // totals
              if (!totals[k]) totals[k] = 0;
              totals[k] += Number(value);
            } else {
              tr += `<td class="${cellClass}">${value}</td>`;
            }
          });
          tr += '</tr>';
          tbody.append(tr);
        }

        // Totals and averages with proper left alignment and exact column width matching
        let totalRow = '';
        let avgRow = '';
        
        // Define exact column widths to match header columns
        const columnWidths = [
          '90px',  // gross_sales
          '90px',  // vatable_sales  
          '90px',  // vat_exempt_sales
          '90px',  // vat_amount
          '90px',  // sc_pwd_discount
          '90px',  // regular_discount
          '70px',  // void
          '70px',  // return
          '90px',  // net_sales
          '90px',  // cash_payment
          '90px',  // card_payment
          '90px',  // other_tender
          '120px', // net_sales_percentage_rent
          '80px',  // transaction_count
          '80px'   // guest_count
        ];
        
        [
          'gross_sales','vatable_sales','vat_exempt_sales','vat_amount',
          'sc_pwd_discount','regular_discount','void','return','net_sales','cash_payment','card_payment','other_tender',
          'net_sales_percentage_rent','transaction_count','guest_count'
        ].forEach((key, index) => {
          let totalValNum = totals[key] ? totals[key] : 0;
          let avgValNum = totals[key] ? (totals[key]/24) : 0;
          let totalVal, avgVal;
          
          if (key === 'transaction_count' || key === 'guest_count') {
            totalVal = String(Math.round(totalValNum));
            avgVal = avgValNum.toFixed(2);
          } else {
            totalVal = totalValNum.toFixed(2);
            avgVal = avgValNum.toFixed(2);
          }
          
          // Use exact width matching with header columns and left alignment
          let cellStyle = `width: ${columnWidths[index]}; min-width: ${columnWidths[index]}; max-width: ${columnWidths[index]}; padding: 8px 4px; text-align: left; font-weight: bold; box-sizing: border-box;`;
          
          totalRow += `<td style="${cellStyle}">${totalVal}</td>`;
          avgRow += `<td style="${cellStyle}">${avgVal}</td>`;
        });
        $('#total-row').html(totalRow);
        $('#average-row').html(avgRow);
        $('#export-excel').prop('disabled', false);
      },
      error: function() {
        console.error('Failed to load sales data');
        $('#export-excel').prop('disabled', true);
      }
    });
  }

  // Load Report button handler
  $('#load-report').on('click', function() {
    const date = $('#report-date').val();
    const tenantId = $('#tenant-filter').val();
    
    if (!date) {
      alert('Please select a date');
      return;
    }
    
    if (!tenantId) {
      alert('Please select a tenant');
      return;
    }
    
    // Show loading state
    const tbody = $('#report-tbody');
    tbody.html(`
      <tr>
        <td colspan="21" class="text-center py-4">
          <i class="fas fa-spinner fa-spin me-2"></i>
          Loading hourly sales report...
        </td>
      </tr>
    `);
    
    // Disable button during loading
    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    
    loadHourlySales(date, tenantId);
    
    // Re-enable button after loading
    setTimeout(() => {
      $(this).prop('disabled', false).html('<i class="fa fa-search"></i> Load Report');
    }, 1000);
  });

  // On date change, clear table and show instruction message
  $('#report-date-picker').on('change.datetimepicker', function(e) {
    const date = e.date ? e.date.format('YYYY-MM-DD') : moment().format('YYYY-MM-DD');
    $('#report-date').val(date);
    setPeriodAndGenerated(e.date || moment());
    
    // Clear table and show instruction message
    const tbody = $('#report-tbody');
    tbody.html(`
      <tr>
        <td colspan="21" class="text-center py-4 text-muted">
          <i class="fas fa-info-circle me-2"></i>
          Please select a date and tenant, then click "Load Report" to view the hourly sales data.
        </td>
      </tr>
    `);
    $('#total-row, #average-row').empty();
    $('#export-excel').prop('disabled', true);
  });

  // On tenant change, clear table and show instruction message
  $('#tenant-filter').on('change', function() {
    // Clear table and show instruction message
    const tbody = $('#report-tbody');
    tbody.html(`
      <tr>
        <td colspan="21" class="text-center py-4 text-muted">
          <i class="fas fa-info-circle me-2"></i>
          Please select a date and tenant, then click "Load Report" to view the hourly sales data.
        </td>
      </tr>
    `);
    $('#total-row, #average-row').empty();
    $('#export-excel').prop('disabled', true);
  });

  // Export to Excel button handler
  $('#export-excel').on('click', function() {
    const date = $('#report-date').val();
    const tenantId = $('#tenant-filter').val();
    
    if (!date || !tenantId) {
      alert('Please select both date and tenant before exporting');
      return;
    }
    
    // Build export URL
    let url = "{{ route('commercial.sales-report.export') }}";
    url += `?date=${encodeURIComponent(date)}&tenant_id=${encodeURIComponent(tenantId)}`;
    window.location.href = url;
  });
});
</script>
@endpush

