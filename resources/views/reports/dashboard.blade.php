@extends('layouts.master')
@section('title', 'Finance Reports')
@push('styles')
  @php
    // The <input type="month"> value is "YYYY-MM"
    $monthYear = request('month', now()->format('Y-m'));
    // Split into [year, month]
    [$currentYear, $currentMonth] = explode('-', $monthYear);
  @endphp
  <!-- DataTables -->
  <link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
  <link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
  <link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <style>
    .print-btn {
      transition: all 0.3s ease;
    }

    .print-btn:hover {
      background-color: rgba(173, 216, 230, 0.2) !important;
    }

    .dashboard-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    .dashboard-header h1 {
      font-size: 2rem;
      font-weight: 700;
      color: #2563eb;
      margin: 0;
    }

    .dashboard-header .search-bar {
      width: 320px;
      max-width: 100%;
      background: #f3f4f6;
      border-radius: 8px;
      padding: 0.5rem 1rem;
      border: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
    }

    .dashboard-header .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      width: 100%;
      font-size: 1rem;
      color: #22223b;
    }

    .dashboard-header .search-bar i {
      color: #2563eb;
      margin-right: 0.5rem;
    }

    /* Make the printable container full-width */
    .print-content {
      width: 100% !important;
      max-width: 100% !important;
      padding: 0 !important;
      margin: 0 auto !important;
    }

    /* Utility & layout */
    .print-btn {
      transition: all 0.3s ease;
    }

    .print-btn:hover {
      background-color: rgba(173, 216, 230, 0.2) !important;
    }

    .dashboard-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    .dashboard-header h1 {
      font-size: 2rem;
      font-weight: 700;
      color: #2563eb;
      margin: 0;
    }

    .dashboard-header .search-bar {
      width: 320px;
      max-width: 100%;
      background: #f3f4f6;
      border-radius: 8px;
      padding: 0.5rem 1rem;
      border: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
    }

    .dashboard-header .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      width: 100%;
      font-size: 1rem;
      color: #22223b;
    }

    .dashboard-header .search-bar i {
      color: #2563eb;
      margin-right: 0.5rem;
    }

    .report-tabs {
      border-bottom: 2px solid #e74c3c;
      margin-bottom: 1.5rem;
      padding: 0.5rem 1rem 0 1rem;
      background: #fff;
      border-radius: 8px 8px 0 0;
      display: flex;
      align-items: center;
    }

    .report-tabs .nav-link {
      border: none;
      border-radius: 0;
      color: #22223b;
      font-weight: 500;
      background: transparent;
      margin-right: 0.5rem;
    }

    .report-tabs .nav-link.active {
      background: #fff;
      border-bottom: 2px solid #2563eb;
      color: #2563eb;
      font-weight: 700;
    }

    .report-header-row {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      margin-bottom: 1rem;
    }

    .report-date {
      background: #f3f4f6;
      border-radius: 6px;
      padding: 0.5rem 1rem;
      font-size: 1rem;
      color: #22223b;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .report-logo {
      max-width: 120px;
      max-height: 60px;
    }

    /* Table styling */
    .report-table th,
    .report-table td {
      font-size: 0.95rem;
      text-align: right;
      vertical-align: middle;
      border: 1px solid #dee2e6 !important;
      padding: 0.4rem 0.6rem;
    }

    .report-table th {
      background: #f8f9fa;
      font-weight: 600;
      color: #22223b;
      text-align: center;
    }

    .report-table td:first-child,
    .report-table th:first-child {
      text-align: center;
    }

    /* Signature block */
    .report-signature {
      margin-top: 2.5rem;
      display: flex;
      justify-content: space-between;
      gap: 2rem;
    }

    .report-signature .sig-block {
      width: 45%;
      text-align: center;
    }

    .report-signature .sig-line {
      border-bottom: 1px solid #22223b;
      margin: 2.5rem 0 0.5rem 0;
      width: 100%;
      display: inline-block;
    }

    /* Sidebar & toolbar */
    .sticky-toolbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #fff;
      padding: 1rem;
      border-bottom: 1px solid #dee2e6;
    }

    .filter-toolbar {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .filter-group {
      flex: 1;
      min-width: 200px;
    }

    select.select2-store {
      width: 100%;
    }

    /* Responsive signature */
    @media (max-width: 991px) {
      .report-signature {
        flex-direction: column;
        gap: 1.5rem;
      }

      .report-signature .sig-block {
        width: 100%;
      }
    }

    /* Global table defaults */
    table {
      width: 100%;
      border-collapse: collapse;
    }

    .sales-report-table {
      width: 100%;
      margin: 0;
      padding: 0;
    }

    @media (max-width: 991px) {
      .report-signature {
        flex-direction: column;
        gap: 1.5rem;
      }

      .report-signature .sig-block {
        width: 100%;
      }
    }

    /* PRINT STYLES */
    @media print {

      /* A4 landscape, small margins */
      /* 1) A4 landscape, tiny margins */
      @page {
        size: A4 portrait;
        margin: 2mm 2mm 2mm 2mm;
      }

      /* 2) Full-width container */
      html,
      body,
      .print-content {
        width: 100%;
        height: auto;
        margin: 0;
        padding: 0;
        font-family: "Times New Roman", Georgia, serif !important;
        font-size: 10pt !important;
        line-height: 1.4 !important;
      }

      /* 3) Scale everything down so it fits on one page */
      body {
        zoom: 0.60;
        /* <- tweak between 0.60–0.75 as needed */
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .main-footer {
        display: none !important;
      }

      .content {
        background: #fff !important;
      }

      .card-body.print-content {
        background: #fff !important;
      }

      /* remove the gray header shade */
      .report-table th {
        background: #fff !important;
      }

      /* if you have any other gray panels inside the report, reset them too */
      .report-tabs,
      .report-date {
        background: transparent !important;
      }

      /* ensure table rows stay white */
      .report-table td {
        background: #fff !important;
      }

      /* 4) Prevent any breaks inside the report area */
      .print-content,
      .card-body,
      .table-responsive,
      .report-table {
        page-break-inside: avoid !important;
        break-inside: avoid-page !important;
        margin-bottom: 2mm !important;
      }

      .print-content .mb-2 {
        margin-top: 0 !important;
        padding-top: 0 !important;
      }

      /* 5) Keep the signature block on the same page */
      .report-signature {
        page-break-before: avoid !important;
        page-break-inside: avoid !important;
        break-inside: avoid-page !important;
        margin-top: 3mm !important;
        /* font-size: 12px !important; */
      }

      .report-signature .sig-line {
        margin: 1mm 0 0.25mm 0 !important;
      }

      /* 6) Make the table truly edge-to-edge */
      .report-table,
      .sales-report-table {
        width: 100% !important;
        max-width: none !important;
        table-layout: auto;
      }

      .report-table th,
      .report-table td {
        padding: 1px 2px !important;
        font-size: 10pt !important;
        white-space: nowrap;
        border: 1px solid #000 !important;
        background-color: transparent !important;
      }

      /* 7) Hide any non-report UI */
      .sticky-toolbar,
      .filter-toolbar,
      .main-sidebar,
      .main-header,
      .content-header,
      #errorAlert,
      .export-btn {
        display: none !important;
      }

      /* ====================================
     Force signature blocks onto one row
     ==================================== */
      .report-signature {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        flex-wrap: nowrap !important;
        /* never wrap down to a second line */
        width: 100% !important;
        /* fill the container */
        margin-top: 3rem !important;
        page-break-inside: avoid !important;
      }

      .report-signature .sig-block {
        flex: 1 1 45% !important;
        /* each block takes ~45% of the width */
        max-width: 45% !important;
        box-sizing: border-box !important;
        text-align: center;
      }

      /* if you need extra breathing room between them, tweak the 45% to 48% or add gap */
      .report-signature {
        gap: 1rem !important;
      }

      .mb-2 {
        /* remove any top margin */
        margin-top: 0 !important;
        /* shrink bottom gap between logo + titles */
        margin-bottom: 0.25rem !important;
      }

      /* Remove any built-in margins around the logo row */
      .report-header>.d-flex.justify-content-end {
        margin-top: 0 !important;
        margin-bottom: 0.25rem !important;
      }

      /* Pull the <h4> up tight under the logo */
      .report-header .text-center h4 {
        margin-top: 0 !important;
        /* no extra gap above */
        margin-bottom: 0.25rem !important;
        /* small gap below */
      }

      /* And similarly tighten your subheadings if needed */
      .report-header .text-center h5,
      .report-header .text-center h6 {
        margin-top: 0 !important;
        margin-bottom: 0.25rem !important;
      }
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    .sales-report-table {
      width: 100%;
      margin: 0;
      padding: 0;
    }
  </style>
@endpush
@section('content')


  <div class="card">
    <!-- Alert for errors -->
    <div id="errorAlert" class="alert alert-danger alert-dismissible fade d-none" role="alert">
      <span id="errorMessage"></span>
      <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>

    <div class="card-header bg-danger sticky-toolbar">
      <div class="filter-toolbar">
        <div class="filter-group">
          <label>Trade Name</label>
          <select class="form-control select2-store" id="trade-filter" data-current="">
            <option value="">Select Tenant</option>
            @foreach($tenants as $tenantId => $tenantName)
              <option value="{{ $tenantId }}" {{ $selected_tenant == $tenantId ? 'selected' : '' }}>
                {{ $tenantName }}
              </option>
            @endforeach
          </select>
        </div>

        <div class="filter-group">
          <label>Report Month</label>
          <input type="month" class="form-control" id="report-month" value="{{ request('month', now()->format('Y-m')) }}">
        </div>

        <div class="filter-group" style="flex: 0 0 auto; margin-top: 2rem;">
          {{-- <a href="{{ route('finance.sales-report.export', [
                        'month' => $currentMonth,
                        'year'  => $currentYear
                      ]) }}" class="btn btn-success">
            <i class="fas fa-file-excel"></i> Export to Excel
          </a> --}}
          {{-- <a href="{{ route('finance.sales-report.export', [
                       'month' => $currentMonth,
                        'year'  => $currentYear
                    ]) }}" class="btn btn-success export-btn" id="excelExportLink">
            <i class="fas fa-file-excel"></i> Export to Excel
          </a> --}}

          <!-- Existing print button -->
          {{-- <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
          </button> --}}
          <button type="button" class="btn btn-success" id="generateReport" disabled>
            <i class="fas fa-sync-alt"></i> Generate Report
          </button>
        </div>

        <div class="btn-group ml-auto">
          <button class="btn btn-light export-btn d-none" id="printBtn" onclick="handleExport('print')" disabled>
            <i class="fas fa-print"></i> Print
          </button>
          {{-- <button class="btn btn-light export-btn" id="excelBtn" onclick="handleExport('excel')" disabled>
            <i class="fas fa-file-excel"></i> Excel
          </button> --}}
          <a id="excelExportLink" href="#" data-base="{{ route('finance.sales-report.export') }}"
            class="btn btn-success d-none export-btn ml-2" disabled>
            <i class="fas fa-file-excel"></i> Export to Excel
          </a>
          {{-- <button class="btn btn-light export-btn" id="pdfBtn" onclick="handleExport('pdf')" disabled>
            <i class="fas fa-file-pdf"></i> PDF
          </button> --}}
        </div>
      </div>
    </div>

    <div class="card-body print.content">
      <!-- Updated report header with adjusted logo position -->
      <div class="report-header">
        <div class="d-flex justify-content-end">
          <img src="{{ asset('images/mwm_logo.png') }}" alt="MWM Terminals Logo" style="height: 100px; width: auto;">
        </div>
        <div class="text-center">
          <h4 class="mb-1" id="store-name-display">Tradename / Branch</h4>
          <h5 class="mb-1">CERTIFIED MONTHLY SALES REPORT</h5>
          <h6 class="text-muted">For the month of <span id="reportMonthDisplay">-</span></h6>
        </div>
      </div>

      <div class="table-responsive position-relative">
        <!-- Loading overlay -->
        <div id="loadingOverlay" class="overlay d-none">
          <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Loading...</span>
          </div>
        </div>

        <!-- Existing table structure -->
        <table class="table report-table mb-0">
          <thead>
            <tr>
              <th rowspan="3" class="align-middle text-center !important">Date</th>
              <th colspan="2">Net Sales</th>
              <th colspan="7">Sales Discount</th>
              <th rowspan="3">Other Tax<br><span style="font-weight:400;font-size:0.9em;">(Local Tax)</span></th>
              <th colspan="2" rowspan="2" class="align-middle text-center !important">Service Charge</th>
              <th rowspan="3" class="align-middle text-center !important">Gross Sales</th>
            </tr>
            <tr>
              <th rowspan="2" class="align-middle text-center !important">Vatable Trans.<br><span
                  style="font-weight:400;font-size:0.9em;">(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</span></th>
              <th rowspan="2" class="align-middle text-center !important">SC Vat Exempt Trans.<br><span
                  style="font-weight:400;font-size:0.9em;">(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</span></th>
              <th rowspan="2" class="align-middle text-center !important">Value Added Tax (VAT)</th>
              <th colspan="2">Promo</th>
              <th rowspan="2" class="align-middle text-center !important">Employee's Discount</th>
              <th rowspan="2" class="align-middle text-center !important">Senior Citizen's</th>
              <th rowspan="2" class="align-middle text-center !important">PWD Disc.</th>
              <th rowspan="2">VIP Cards<br><span style="font-weight:400;font-size:0.9em;">if any</span></th>
            </tr>
            <tr>
              <th>With Approval</th>
              <th>Without Approval</th>
              <th>Distributed to Employees</th>
              <th>Retained by Management</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>-</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
            </tr>
            <tr style="font-weight:600;">
              <td>Total</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
              <td>0.00</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="row mt-2">
        <div class="col-md-2">
          <div>Less:</div>
        </div>
        <div class="col-md-6 text-left">

          <div style="text-align: left !important; display: block !important;">Promo Discounts With Approval</div>
          <div style="text-align: left !important; display: block !important;">Promo Discounts Without Approval</div>
          <div style="text-align: left !important; display: block !important;">Employee's Discount</div>
          <div style="text-align: left !important; display: block !important;">Approved VIP Cards</div>
          <div style="text-align: left !important; display: block !important;">SC Vat Exempt Transactions</div>
          <div style="text-align: left !important; display: block !important;">Senior Citizen/PWD Discounts</div>
          <div style="text-align: left !important; display: block !important;">Other Tax</div>
          <div style="text-align: left !important; display: block !important;">Service Charge Distributed to Employees
          </div>
          <div style="text-align: left !important; display: block !important;">Service Charge Retained by Management</div>
        </div>
        <div class="col-md-4 text-right ">
          <div><span id="less-promo-with-approval">₱0.00</span></div>
          <div><span id="less-promo-without-approval">₱0.00</span></div>
          <div><span id="less-employees-discount">₱0.00</span></div>
          <div><span id="less-approved-vip-cards">₱0.00</span></div>
          <div><span id="less-sc-vat-exempt">₱0.00</span></div>
          <div><span id="less-senior-pwd">₱0.00</span></div>
          <div><span id="less-other-tax">₱0.00</span></div>
          <div><span id="less-service-charge-distributed">₱0.00</span></div>
          <div><span id="less-charge-retained">₱0.00</span></div>

        </div>
      </div>

      <div class="row mt-2 text-left">
        <div class="col-md text-left font-weight-bold">Net Sales</div>
        <div class="col-md-6"></div>
        <div class="col-md-5 text-right">
          <span class="amount-field font-weight-bold" id="net-sales-display">₱0.00</span>
        </div>
      </div>
      <div class="row">
        <div class="col-md-2 text-Left">Less 12% VAT</div>
        <div class="col-md-6"></div>
        <div class="col-md-4 text-right">
          <span class="amount-field" id="vat-amount">₱0.00</span>
        </div>
      </div>
      <hr style="border-top: 1px solid #909294; margin: 1rem 0;">
      <div class="row mt-2">
        <div class="col-md-8 text-right font-weight-bold"></div>
        <div class="col-md-4 text-right font-weight-bold" style="text-align:right !important;" id="net-with-vat">₱0.00
        </div>
      </div>

      <div class="row mt-2">
        <div class="col-md-1">
          <div class="reporsection-title">Add:</div>

        </div>
        <div class="col-md-6 text-left">
          <div style="text-align: left !important; display: block !important;">SC Vat Exempt Transactions</div>
          <div style="text-align: left !important; display: block !important;">Promo Discounts Without Approval</div>
          <div style="text-align: left !important; display: block !important;">Other Tax</div>
          <div style="text-align: left !important; display: block !important;">Service Charge Retained by Management</div>
        </div>

        <div class="col-md-5 text-right">
          <div><span id="sc-vat-exempt">₱0.00</span></div>
          <div><span id="promo-without-approval">₱0.00</span></div>
          <div><span id="other-tax">₱0.00</span></div>
          <div><span id="service-charge-retained">₱0.00</span></div>
        </div>
      </div>

      {{--
      <hr style="border-top: 1px solid #909294; margin: 1rem 0;"> --}}

      <div class="row mt-2">
        <div class="col-md-6 text-left font-weight-bold">Net Sales Subject to Percentage rent</div>
        <div class="col-md-6 text-right font-weight-bold"><span id="net-sales-final">₱0.00</span></div>
      </div>
      {{--
      <hr style="border-top: 1px solid #909294; margin: 1rem 0;"> --}}

      <div class="report-signature">
        <div class="sig-block">
          <div class="sig-line"></div>
          <div>Prepared by:</div>
          <div class="mt-2">Tenant Name</div>
          <div style="font-size:0.9em;">Signature over Printed Name</div>
          <div class="mt-2 font-weight-bold">SUPERVISOR/OWNER</div>
          <div style="font-size:0.9em;">(Position)</div>
        </div>
        <div class="sig-block">
          <div class="sig-line"></div>
          <div>Certified Correct by:</div>
          <div class="mt-2">Tenant Name</div>
          <div style="font-size:0.9em;">Signature over Printed Name</div>
          <div class="mt-2 font-weight-bold">AUTHORIZED REPRESENTATIVE</div>
          <div style="font-size:0.9em;">(Position)</div>
        </div>
      </div>
    </div>
  </div>
@endsection

@push('scripts')
  <!-- Load Select2 after jQuery but before custom scripts -->
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <!-- DataTables & Plugins -->
  <script src="{{ asset('plugins/datatables/jquery.dataTables.min.js') }}"></script>
  <script src="{{ asset('plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
  <script src="{{ asset('plugins/datatables-responsive/js/dataTables.responsive.min.js') }}"></script>
  <script src="{{ asset('plugins/datatables-buttons/js/dataTables.buttons.min.js') }}"></script>
  <script src="{{ asset('plugins/datatables-buttons/js/buttons.bootstrap4.min.js') }}"></script>

  <script>
    $(function () {
      // Initialize Select2 with no default selection
      $('.select2-store').select2({
        placeholder: 'Select Tenant',
        allowClear: true,
        width: '100%'
      });

      // Prevent initial data load
      const tenantId = $('#trade-filter').val();
      const month = $('#report-month').val();
      const hasValidFilters = tenantId && tenantId !== '' && tenantId !== 'all' && month;
      if (!hasValidFilters) {
        // Show empty state with zeros
        const tbody = $('.report-table tbody');
        tbody.html(`
              <tr>
                  <td>-</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
              </tr>
              <tr style="font-weight:600;">
                  <td>Total</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
              </tr>
          `);
      }

      // Validate filters and enable/disable generate button
      function validateFilters() {
        const trade = $('#trade-filter').val();
        const month = $('#report-month').val();
        console.log('validateFilters:', { trade, month });

        const isValid = Boolean(trade && month);
        $('#generateReport').prop('disabled', !isValid);
        $('.export-btn').prop('disabled', true);
        return isValid;
      }

      // Wire up both native and Select2 events
      $('#trade-filter').on('change change.select2', validateFilters);
      $('#report-month').on('change', validateFilters);

      // Run initial validation
      validateFilters();

      // Generate Report Handler////
      // $('#generateReport').on('click', function(e) {
      //     e.preventDefault();
      //     if (!validateFilters()) return;

      //     const $btn = $(this);
      //     $btn.prop('disabled', true);
      //     $('#loadingOverlay').removeClass('d-none');

      //     $.ajax({
      //         url: '{{ route("finance.reports") }}',
      //         method: 'GET',
      //         data: {
      //             store: $('#store-filter').val(),
      //             month: $('#report-month').val()
      //         },
      //         success: function(response) {
      //             if (response.status === 'success') {
      //                 updateTableContent(response);
      //                 $('.export-btn').prop('disabled', false);
      //             } else {
      //                 showError(response.message || 'Failed to generate report');
      //             }
      //         },
      //         error: function() {
      //             showError('Failed to generate report. Please try again.');
      //             $('.export-btn').prop('disabled', true);
      //         },
      //         complete: function() {
      //             $('#loadingOverlay').addClass('d-none');
      //             $btn.prop('disabled', false);
      //         }
      //     });
      // });
      $('#generateReport').on('click', function (e) {
        e.preventDefault();
        if (!validateFilters()) {
          // Log selected tenantId for debugging
          console.log('Selected tenantId:', $('#trade-filter').val());
          return;
        }

        // 1) Show spinner, hide/disable all export buttons
        $('#loadingOverlay').removeClass('d-none');
        $('.export-btn').addClass('d-none').prop('disabled', true);

        // 2) AJAX to pull in the table data
        $.ajax({
          url: '{{ route("finance.reports") }}',
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          data: {
            trade: $('#trade-filter').val(),
            month: $('#report-month').val()
          },
          success(response) {
            console.log('AJAX response received:', response);
            if (response.status === 'success') {
              console.log('Daily totals:', response.daily_totals);
              // 3a) Render the table
              updateTableContent(response);

              // 3b) Build the export URL
              const [year, month] = $('#report-month').val().split('-');
              const trade = encodeURIComponent($('#trade-filter').val());
              const base = $('#excelExportLink').data('base');
              const href = `${base}?month=${month}&year=${year}&tenant=${trade}`;

              // 3c) Show & enable the Excel button
              $('#excelExportLink')
                .attr('href', href)
                .removeClass('d-none')
                .prop('disabled', false);

              // Optionally show & enable your Print button too
              $('#printBtn')
                .removeClass('d-none')
                .prop('disabled', false);
            } else {
              showError(response.message || 'Failed to generate report');
            }
          },
          error(xhr, status, error) {
            console.error('AJAX error:', { xhr, status, error });
            console.error('Response text:', xhr.responseText);
            showError('Failed to generate report. Please try again.');
          },
          complete() {
            // 4) Cleanup: hide spinner, re-enable Generate
            $('#loadingOverlay').addClass('d-none');
            $('#generateReport').prop('disabled', false);
          }
        });
      });


      // Consolidated fetch and render function
      function fetchReport(trade, month) {
        $('#loadingOverlay').removeClass('d-none');
        $('.export-btn').prop('disabled', true);

        $.ajax({
          url: '{{ route("finance.reports") }}',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          data: { trade, month },
          success: function (response) {
            console.log('Fetch report response:', response);
            if (response.status === 'success') {
              console.log('Updating table with daily totals:', response.daily_totals);
              updateTableContent(response);
              $('.export-btn').prop('disabled', false);
            } else {
              showError(response.message || 'Failed to generate report');
            }
          },
          error: function (xhr, status, error) {
            console.error('Fetch report AJAX error:', { xhr, status, error });
            console.error('Response text:', xhr.responseText);
            showError('Failed to generate report. Please try again.');
          },
          complete: function () {
            $('#loadingOverlay').addClass('d-none');
            validateFilters();
          }
        });
      }

      // Add formatCurrency helper function
      function formatCurrency(num) {
        return new Intl.NumberFormat('en-PH', {
          style: 'currency',
          currency: 'PHP',
          minimumFractionDigits: 2
        }).format(num || 0);
      }

      // Update table content without page reload
      function updateTableContent(response) {
        console.log('updateTableContent called with:', response);
        if (!response.daily_totals || !response.totals) {
          console.log('Invalid response structure');
          return;
        }

        const tbody = $('.report-table tbody');
        tbody.empty();

        if (Object.keys(response.daily_totals).length === 0) {
          tbody.append(`
                  <tr>${Array(14).fill('<td>0.00</td>').join('')}</tr>
                  <tr style="font-weight:600;"><td>Total</td>${Array(13).fill('<td>0.00</td>').join('')}</tr>
              `);
          $('#net-sales-display').text(formatCurrency(0));
          $('#vat-amount').text(formatCurrency(0));
          $('#net-with-vat').text(formatCurrency(0));
          $('#net-sales-final').text(formatCurrency(0));
          return;
        }

        // Add daily rows
        Object.entries(response.daily_totals).forEach(([date, daily]) => {
          let day = date.split('-')[2];
          tbody.append(`
                  <tr>
                      <td>${day}</td>
                      <td>${formatNumber(daily.net_sales)}</td>
                      <td>${formatNumber(daily.sc_vat_exempt_sales)}</td>
                      <td>${formatNumber(daily.vat_amount)}</td>
                      <td>${formatNumber(daily.promo_with_approval)}</td>
                      <td>${formatNumber(daily.promo_without_approval)}</td>
                      <td>${formatNumber(daily.employee_discount)}</td>
                      <td>${formatNumber(daily.senior_discount)}</td>
                      <td>${formatNumber(daily.pwd_discount)}</td>
                      <td>${formatNumber(daily.vip_discount)}</td>
                      <td>${formatNumber(daily.other_tax)}</td>
                      <td>${formatNumber(daily.service_charge_distributed)}</td>
                      <td>${formatNumber(daily.service_charge_retained)}</td>
                      <td>${formatNumber(daily.gross_sales)}</td>
                  </tr>
              `);
        });

        // Add totals row
        const t = response.totals;
        tbody.append(`
              <tr style="font-weight:600;">
                  <td>Total</td>
                  <td>${formatNumber(t.net_sales)}</td>
                  <td>${formatNumber(t.sc_vat_exempt_sales)}</td>
                  <td>${formatNumber(t.vat_amount)}</td>
                  <td>${formatNumber(t.promo_with_approval)}</td>
                  <td>${formatNumber(t.promo_without_approval)}</td>
                  <td>${formatNumber(t.employee_discount)}</td>
                  <td>${formatNumber(t.senior_discount)}</td>
                  <td>${formatNumber(t.pwd_discount)}</td>
                  <td>${formatNumber(t.vip_discount)}</td>
                  <td>${formatNumber(t.other_tax)}</td>
                  <td>${formatNumber(t.service_charge_distributed)}</td>
                  <td>${formatNumber(t.service_charge_retained)}</td>
                  <td>${formatNumber(t.gross_sales)}</td>
              </tr>
          `);

        // Update display values
        $('#net-sales-display').text(formatCurrency(t.net_sales));
        $('#vat-amount').text(formatCurrency(t.vat_amount));
        $('#net-with-vat').text(formatCurrency(t.net_ex_vat));

        // Update Less section
        $('#less-promo-with-approval').text(formatCurrency(t.promo_with_approval));
        $('#less-promo-without-approval').text(formatCurrency(t.promo_without_approval));
        $('#less-employees-discount').text(formatCurrency(t.employee_discount));
        $('#less-approved-vip-cards').text(formatCurrency(t.vip_discount));
        $('#less-sc-vat-exempt').text(formatCurrency(t.sc_vat_exempt_sales));
        $('#less-senior-pwd').text(formatCurrency(t.senior_pwd));
        $('#less-other-tax').text(formatCurrency(t.other_tax));
        $('#less-service-charge-distributed').text(formatCurrency(t.service_charge_distributed));
        $('#less-charge-retained').text(formatCurrency(t.service_charge_retained));

        // Update Add section
        $('#sc-vat-exempt').text(formatCurrency(t.sc_vat_exempt_sales));
        $('#promo-without-approval').text(formatCurrency(t.promo_without_approval));
        $('#other-tax').text(formatCurrency(t.other_tax));
        $('#service-charge-retained').text(formatCurrency(t.service_charge_retained));

        // Final Net Sales Subject to Percentage Rent
        $('#net-sales-final').text(formatCurrency(t.net_subject_to_rent));

        // Update store name
        const selectedTrade = $('#trade-filter option:selected').text().trim();
        $('#store-name-display').text(selectedTrade === 'All Tenant' ? 'All Tenant' : selectedTrade);
      }


    }
      function showError(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').removeClass('d-none');
        setTimeout(() => {
          $('#errorAlert').addClass('d-none');
        }, 5000);
      }

      // Helper functions
      function formatNumber(num) {
        return new Intl.NumberFormat('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(num || 0);
      }

      function calculateTotals(dailyData) {
        const totals = {
          net_sales: 0,
          vat_exempt_sales: 0,
          promo_with_approval: 0,
          promo_without_approval: 0,
          employee_discount: 0,
          senior_discount: 0,
          pwd_discount: 0,
          vip_discount: 0,
          other_tax: 0,
          service_charge_distributed: 0,
          service_charge_retained: 0,
          service_charge: 0,
          gross_sales: 0
        };

        Object.values(dailyData).forEach(daily => {
          Object.keys(totals).forEach(key => {
            totals[key] += parseFloat(daily[key] || 0);
          });
        });

        return totals;
      }

      // Initial load
      if ($('#trade-filter').val() && $('#report-month').val()) {
      fetchReport(
        $('#trade-filter').val(),
        $('#report-month').val()
      );
    }

    // Initialize month display
    const initialMonth = $('#report-month').val();
    if (initialMonth) {
      const monthYear = moment(initialMonth).format('MMMM YYYY');
      $('#reportMonthDisplay').text(monthYear.toUpperCase());
    }

    // Update month display on change
    $('#report-month').on('change', function () {
      const date = $(this).val();
      if (date) {
        const monthYear = moment(date).format('MMMM YYYY');
        $('#reportMonthDisplay').text(monthYear.toUpperCase());
      } else {
        $('#reportMonthDisplay').text('-');
      }
    });

    // Add handleExport function
    window.handleExport = function (type) {
      switch (type) {
        case 'print':
          window.print();
          break;
        case 'excel':
          // Excel export logic here
          console.log('Excel export');
          break;
        case 'pdf':
          // PDF export logic here
          console.log('PDF export');
          break;
      }
    };
  });
  </script>
@endpush