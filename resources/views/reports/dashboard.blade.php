@extends('layouts.master')
@section('title', 'Finance Reports')
@push('styles')
@php
    // The <input type="month"> value is "YYYY-MM"
    $monthYear    = request('month', now()->format('Y-m'));
    // Split into [year, month]
    [$currentYear, $currentMonth] = explode('-', $monthYear);
@endphp
<!-- DataTables -->
<link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-responsive/css/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" href="{{ asset('plugins/datatables-buttons/css/buttons.bootstrap4.min.css') }}">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
/* Consolidated certified monthly sales report styles
   - kept print-ready A4 portrait rules
   - removed duplicate selectors and tightened spacing
*/

.print-btn { transition: all 0.3s ease; }
.print-btn:hover { background-color: rgba(173, 216, 230, 0.2) !important; }

.dashboard-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
.dashboard-header h1 { font-size: 2rem; font-weight: 700; color: #2563eb; margin: 0; }
.dashboard-header .search-bar { width: 320px; max-width: 100%; background: #f3f4f6; border-radius: 8px; padding: 0.5rem 1rem; border: 1px solid #e5e7eb; display: flex; align-items: center; }
.dashboard-header .search-bar input { border: none; background: transparent; outline: none; width: 100%; font-size: 1rem; color: #22223b; }
.dashboard-header .search-bar i { color: #2563eb; margin-right: 0.5rem; }

/* Make the printable container full-width */
.print-content { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 auto !important; }

.report-tabs { border-bottom: 2px solid #e74c3c; margin-bottom: 1.5rem; padding: 0.5rem 1rem 0 1rem; background: #fff; border-radius: 8px 8px 0 0; display: flex; align-items: center; }
.report-tabs .nav-link { border: none; border-radius: 0; color: #22223b; font-weight: 500; background: transparent; margin-right: 0.5rem; }
.report-tabs .nav-link.active { background: #fff; border-bottom: 2px solid #2563eb; color: #2563eb; font-weight: 700; }

.report-header-row { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 1rem; }
.report-date { background: #f3f4f6; border-radius: 6px; padding: 0.5rem 1rem; font-size: 1rem; color: #22223b; display: flex; align-items: center; gap: 0.5rem; }
.report-logo { height: 100px; width: auto; max-width: 160px; }

/* Header layout: center titles and position logo to the right */
.report-header { position: relative; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
.logo-wrap { position: absolute; right: 0.5rem; top: 0; display: flex; align-items: center; justify-content: flex-end; }
.report-header .text-center { max-width: calc(100% - 140px); padding: 0 12px; text-align: center; white-space: normal; }

@media (max-width: 768px) {
  .report-header { flex-direction: column; align-items: center; }
  .logo-wrap { position: static !important; order: -1; margin-bottom: 6px; right: auto; top: auto; }
  .report-header .text-center { max-width: 100% !important; padding: 0 6px !important; }
  .report-logo { height: 80px; }
}

@media (max-width: 420px) {
  .report-logo { height: 60px; }
  .report-header .text-center h4 { font-size: 1rem; }
  .report-header .text-center h5 { font-size: 0.95rem; }
  .report-header .text-center h6 { font-size: 0.85rem; }
}

/* Table styling */
.report-table th, .report-table td { font-size: 0.95rem; text-align: right; vertical-align: middle; border: 1px solid #dee2e6 !important; padding: 0.4rem 0.6rem; }
.report-table th { background: #f8f9fa; font-weight: 600; color: #22223b; text-align: center; font-size: 0.82rem; line-height: 1.05; padding: 0.35rem 0.45rem; white-space: normal; overflow-wrap: break-word; word-wrap: break-word; hyphens: auto; }
.report-table td:first-child, .report-table th:first-child { text-align: center; }

@media (min-width: 992px) {
  .report-table th:nth-child(1), .report-table td:nth-child(1) { width: 6%; }
  .report-table th:nth-child(2), .report-table td:nth-child(2) { width: 12%; }
  .report-table th:nth-child(3), .report-table td:nth-child(3) { width: 10%; }
  .report-table th:nth-child(4), .report-table td:nth-child(4) { width: 8%; }
  .report-table th:nth-child(5), .report-table td:nth-child(5) { width: 7%; }
  .report-table th:nth-child(6), .report-table td:nth-child(6) { width: 6%; }
  .report-table th:nth-child(7), .report-table td:nth-child(7) { width: 6%; }
  .report-table th:nth-child(8), .report-table td:nth-child(8) { width: 6%; }
  .report-table th:nth-child(9), .report-table td:nth-child(9) { width: 6%; }
  .report-table th:nth-child(10), .report-table td:nth-child(10) { width: 6%; }
  .report-table th:nth-child(11), .report-table td:nth-child(11) { width: 6%; }
  .report-table th:nth-child(12), .report-table td:nth-child(12) { width: 6%; }
  .report-table th:nth-child(13), .report-table td:nth-child(13) { width: 6%; }
  .report-table th:nth-child(14), .report-table td:nth-child(14) { width: 16%; }
}

/* Signature block */
.report-signature { margin-top: 2.5rem; display: flex; justify-content: space-between; gap: 2rem; }
.report-signature .sig-block { width: 45%; text-align: center; }
.report-signature .sig-line { border-bottom: 1px solid #22223b; margin: 2.5rem 0 0.5rem 0; width: 100%; display: inline-block; }

/* Sidebar & toolbar */
.sticky-toolbar { position: sticky; top: 0; z-index: 100; background: #fff; padding: 1rem; border-bottom: 1px solid #dee2e6; }
.filter-toolbar { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.filter-group { flex: 1; min-width: 200px; }
/* Select2 sizing + truncation for dropdown inside sticky toolbar */
select.select2-store { width: 100%; }
.select2-container { width: 100% !important; }
.select2-container--default .select2-selection--single { height: calc(2.25rem + 2px); border-radius: 6px; padding: 0.25rem 0.5rem; }
.select2-selection__rendered { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; max-width: 100%; }
.select2-selection__placeholder { color: #6b7280; }

@media (max-width: 991px) {
  .report-signature { flex-direction: column; gap: 1.5rem; }
  .report-signature .sig-block { width: 100%; }
}

/* Global table defaults */
table { width: 100%; border-collapse: collapse; }
.sales-report-table { width: 100%; margin: 0; padding: 0; }

/* Compact label/value rows used in Less/Add sections */
.report-list { margin-top: 0.25rem; }
.report-list .report-line { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 2px 0; white-space: nowrap; }
.report-list .report-line .label { flex: 1 1 auto; text-align: left; overflow: hidden; text-overflow: ellipsis; }
.report-list .report-line .value { flex: 0 0 auto; min-width: 90px; text-align: right; }
.report-list .report-line .value span { display: inline-block; }

@media (max-width: 575px) {
  .report-list .report-line { gap: 0.4rem; }
  .report-list .report-line .label { font-size: 0.95rem; }
  .report-list .report-line .value { min-width: 70px; }
}

/* PRINT STYLES */
@media print {
  @page { size: A4 portrait; margin: 10mm; }

  html, body, .print-content { width: 100% !important; height: auto !important; margin: 0 !important; padding: 0 !important; font-family: "Times New Roman", Georgia, serif !important; font-size: 10pt !important; line-height: 1.25 !important; color: #000 !important; background: #fff !important; }

  /* Hide UI chrome */
  .sticky-toolbar, .filter-toolbar, .main-sidebar, .main-header, .content-header, #errorAlert, .export-btn, .main-footer, .btn { display: none !important; }

  .card, .card-header, .card-body { box-shadow: none !important; border: none !important; background: transparent !important; }
  .table-responsive { overflow: visible !important; }

  .report-table, .sales-report-table { width: 100% !important; max-width: none !important; table-layout: fixed !important; border-collapse: collapse !important; }

  .report-table th, .report-table td { padding: 2px 4px !important; font-size: 9.5pt !important; vertical-align: middle !important; border: 1px solid #000 !important; background: transparent !important; white-space: nowrap !important; }

  .report-table th, .report-table td, .amount-field, .report-table .numeric { font-variant-numeric: tabular-nums; -moz-font-feature-settings: "tnum" 1; -webkit-font-feature-settings: "tnum" 1; font-feature-settings: "tnum" 1; }

  /* Print-specific column width hints */
  /* .report-table th:nth-child(1), .report-table td:nth-child(1) { width: 6% !important; }
  .report-table th:nth-child(2), .report-table td:nth-child(2) { width: 10% !important; }
  .report-table th:nth-child(3), .report-table td:nth-child(3) { width: 9% !important; }
  .report-table th:nth-child(4), .report-table td:nth-child(4) { width: 7% !important; }
  .report-table th:nth-child(5), .report-table td:nth-child(5) { width: 7% !important; }
  .report-table th:nth-child(6), .report-table td:nth-child(6) { width: 6% !important; }
  .report-table th:nth-child(7), .report-table td:nth-child(7) { width: 6% !important; }
  .report-table th:nth-child(8), .report-table td:nth-child(8) { width: 6% !important; }
  .report-table th:nth-child(9), .report-table td:nth-child(9) { width: 6% !important; } */
  .report-table th:nth-child(10), .report-table td:nth-child(10) { width: 6% !important; }
  .report-table th:nth-child(11), .report-table td:nth-child(11) { width: 6% !important; }
  .report-table th:nth-child(12), .report-table td:nth-child(12) { width: 6% !important; }
  .report-table th:nth-child(13), .report-table td:nth-child(13) { width: 6% !important; }
  .report-table th:nth-child(14), .report-table td:nth-child(14) { width: 16% !important; }

  .print-content, .card-body, .table-responsive, .report-table, .report-signature { page-break-inside: avoid !important; break-inside: avoid-page !important; }
  .report-signature { display: flex !important; flex-direction: row !important; justify-content: space-between !important; gap: 1rem !important; }
  .report-signature .sig-block { flex: 1 1 45% !important; max-width: 45% !important; }

  .report-header > .d-flex.justify-content-end { margin-top: 0 !important; margin-bottom: 0.25rem !important; }
  .report-header .text-center h4, .report-header .text-center h5, .report-header .text-center h6 { margin: 0 !important; padding: 0 !important; }

  body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
        <select class="form-control select2-store" id="trade-filter" data-current="" aria-label="Select Tenant">
          <!-- empty option so Select2 placeholder displays correctly -->
          <option value=""></option>
          @foreach($tenants as $tenantId => $tenantName)
            <option value="{{ $tenantId }}" {{ $selected_tenant == $tenantId ? 'selected' : '' }}>
              {{ $tenantName }}
            </option>
          @endforeach
        </select>
      </div>
            
            <div class="filter-group">
                <label>Report Month</label>
                <input type="month" class="form-control" id="report-month" 
                    value="{{ request('month', now()->format('Y-m')) }}">
            </div>

            <div class="filter-group" style="flex: 0 0 auto; margin-top: 2rem;">
                {{-- <a href="{{ route('finance.sales-report.export', [
                      'month' => $currentMonth,
                      'year'  => $currentYear
                    ]) }}"
                  class="btn btn-success">
                  <i class="fas fa-file-excel"></i> Export to Excel
                </a> --}}
                {{-- <a href="{{ route('finance.sales-report.export', [
                     'month' => $currentMonth,
                      'year'  => $currentYear
                  ]) }}"
                   class="btn btn-success export-btn"
                   id="excelExportLink">
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
                <a id="excelExportLink"
                  href="#"
                  data-base="{{ route('finance.sales-report.export') }}"
                  class="btn btn-success d-none export-btn ml-2"
                  disabled>
                  <i class="fas fa-file-excel"></i> Export to Excel
                </a>
                {{-- <button class="btn btn-light export-btn" id="pdfBtn" onclick="handleExport('pdf')" disabled>
                    <i class="fas fa-file-pdf"></i> PDF
                </button> --}}
            </div>
        </div>
    </div>

  <div class="card-body print-content">
        <!-- Updated report header with adjusted logo position -->
    <div class="report-header">
      <div class="logo-wrap">
        <img src="{{ asset('images/mwm_logo.png') }}" alt="MWM Terminals Logo" class="report-logo">
      </div>
      <div class="text-center" style="width:100%;">
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
                    <th rowspan="3"  class="align-middle text-center !important">Date</th>
                    <th colspan="2">Net Sales</th>
                    <th colspan="7">Sales Discount</th>
                    <th rowspan="3">Other Tax<br><span style="font-weight:400;font-size:0.9em;">(Local Tax)</span></th>
                    <th colspan="2" rowspan="2" class="align-middle text-center !important">Service Charge</th>
                    <th rowspan="3" class="align-middle text-center !important">Gross Sales</th>
                  </tr>
                  <tr>
                    <th rowspan="2" class="align-middle text-center !important">Vatable Trans.<br><span style="font-weight:400;font-size:0.9em;">(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</span></th>
                    <th rowspan="2" class="align-middle text-center !important">SC Vat Exempt Trans.<br><span style="font-weight:400;font-size:0.9em;">(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</span></th>
                    <th rowspan="2" class="align-middle text-center !important">Value Added Tax (VAT)</th>
                    <th colspan="2">Promo</th>
                    <th rowspan="2" class="align-middle text-center !important">Employee's Discount</th>
                    <th rowspan="2"class="align-middle text-center !important">Senior Citizen's</th>
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
          <div class="col-12">
            <div>Less:</div>
            <div class="report-list">
              <div class="report-line"><div class="label">Promo Discounts With Approval</div><div class="value"><span id="less-promo-with-approval">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Promo Discounts Without Approval</div><div class="value"><span id="less-promo-without-approval">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Employee's Discount</div><div class="value"><span id="less-employees-discount">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Approved VIP Cards</div><div class="value"><span id="less-approved-vip-cards">₱0.00</span></div></div>
              <div class="report-line"><div class="label">SC Vat Exempt Transactions</div><div class="value"><span id="less-sc-vat-exempt">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Senior Citizen/PWD Discounts</div><div class="value"><span id="less-senior-pwd">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Other Tax</div><div class="value"><span id="less-other-tax">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Service Charge Distributed to Employees</div><div class="value"><span id="less-service-charge-distributed">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Service Charge Retained by Management</div><div class="value"><span id="less-charge-retained">₱0.00</span></div></div>
            </div>
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
          <div class="col-md-4 text-right font-weight-bold" style="text-align:right !important;" id="net-with-vat">₱0.00</div>
        </div>

        <div class="row mt-2">
          <div class="col-12">
            <div class="reporsection-title">Add:</div>
            <div class="report-list">
              <div class="report-line"><div class="label">SC Vat Exempt Transactions</div><div class="value"><span id="sc-vat-exempt">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Promo Discounts Without Approval</div><div class="value"><span id="promo-without-approval">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Other Tax</div><div class="value"><span id="other-tax">₱0.00</span></div></div>
              <div class="report-line"><div class="label">Service Charge Retained by Management</div><div class="value"><span id="service-charge-retained">₱0.00</span></div></div>
            </div>
          </div>
        </div>
        
        {{-- <hr style="border-top: 1px solid #909294; margin: 1rem 0;"> --}}
        
        <div class="row mt-2">
          <div class="col-md-6 text-left font-weight-bold">Net Sales Subject to Percentage rent</div>
          <div class="col-md-6 text-right font-weight-bold" ><span id="net-sales-final">₱0.00</span></div>
        </div>
        {{-- <hr style="border-top: 1px solid #909294; margin: 1rem 0;"> --}}
        
        <div class="report-signature" >
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
$(function() {
    // Initialize Select2 with no default selection
  $('.select2-store').select2({
    placeholder: 'Select Tenant',
    allowClear: true,
    width: 'resolve',
    dropdownParent: $('.filter-toolbar'),
    dropdownAutoWidth: true
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
    const tenant = $('#trade-filter').val();
    const month = $('#report-month').val();
    console.log('validateFilters:', { tenant, month });
        
    const isValid = Boolean(tenant && month);
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
    $('#generateReport').on('click', function(e) {
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
            tenant: $('#trade-filter').val(),
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
              const tenantId      = encodeURIComponent($('#trade-filter').val());
              const base          = $('#excelExportLink').data('base');
              const href          = `${base}?month=${month}&year=${year}&tenant=${tenantId}`;

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
            console.error('AJAX error:', {xhr, status, error});
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
    function fetchReport(tenant, month) {
        $('#loadingOverlay').removeClass('d-none');
        $('.export-btn').prop('disabled', true);
        
        $.ajax({
            url: '{{ route("finance.reports") }}',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            data: { tenant, month },
            success: function(response) {
                console.log('Fetch report response:', response);
                if (response.status === 'success') {
                    console.log('Updating table with daily totals:', response.daily_totals);
                    updateTableContent(response);
                    $('.export-btn').prop('disabled', false);
                } else {
                    showError(response.message || 'Failed to generate report');
                }
            },
            error: function(xhr, status, error) {
                console.error('Fetch report AJAX error:', {xhr, status, error});
                console.error('Response text:', xhr.responseText);
                showError('Failed to generate report. Please try again.');
            },
            complete: function() {
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
        if (!response.daily_totals) {
            console.log('No daily_totals in response');
            return;
        }
        
        const tbody = $('.report-table tbody');
        console.log('Found tbody element:', tbody.length);
        
        //  const totals = calculateTotals(response.daily_totals);
        tbody.empty();

        if (Object.keys(response.daily_totals).length === 0) {
            console.log('Empty daily_totals, showing empty row');
            tbody.append(`
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
            $('#net-sales-display').text(formatCurrency(0));
            $('#vat-amount').text(formatCurrency(0));
            return;
        }
        // Add daily rows
        Object.entries(response.daily_totals).forEach(([date, daily]) => {
            // Calculate VAT column (12% of net_sales) inside the loop
            // const calculatedVat = parseFloat(daily.net_sales || 0) * 0.12; 
            const calculatedVat = daily.net_sales  * 0.12; // Calculate VAT as 12% of net sales
            const calculatedVatFormatted = formatNumber(calculatedVat);
          let day = date.split('-')[2];
            tbody.append(`
                <tr>
                    <td>${day}</td>
                    <td>${formatNumber(daily.net_sales)}</td>
                    <td>${formatNumber(daily.vat_exempt_sales)}</td>
                    <td>${formatNumber(calculatedVat)}</td>
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

        // Add totals row with calculations
        const totals = calculateTotals(response.daily_totals);
        const totalVat = totals.net_sales * 0.12; // Calculate total VAT
        tbody.append(`
            <tr style="font-weight:600;">
                <td>Total</td>
                <td>${formatNumber(totals.net_sales)}</td>
                <td>${formatNumber(totals.vat_exempt_sales)}</td>
                <td>${formatNumber(totalVat)}</td>
                <td>${formatNumber(totals.promo_with_approval)}</td>
                <td>${formatNumber(totals.promo_without_approval)}</td>
                <td>${formatNumber(totals.employee_discount)}</td>
                <td>${formatNumber(totals.senior_discount)}</td>
                <td>${formatNumber(totals.pwd_discount)}</td>
                <td>${formatNumber(totals.vip_discount)}</td>
                <td>${formatNumber(totals.other_tax)}</td>
                <td>${formatNumber(totals.service_charge_distributed)}</td>
                <td>${formatNumber(totals.service_charge_retained)}</td>
                <td>${formatNumber(totals.gross_sales)}</td>
            </tr>
        `);

        // Update net sales display
        const formattedNetSales = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(totals.net_sales || 0);
        
        // Update net sales display
        // $('#net-sales-display').text(formattedNetSales);
         // Calculate totals
        // const totals = calculateTotals(response.daily_totals);
        
        // Update store name in header
        const selectedTrade = $('#trade-filter option:selected').text().trim();
        $('#store-name-display').text(selectedTrade === 'All Tenant' ? 'All Tenant' : selectedTrade);

        // 1) Aggregate line-item totals

        const serviceChargeTotal = parseFloat(totals.service_charge_distributed || 0) + 
                                parseFloat(totals.service_charge_retained || 0);
        
        const promosTotal = parseFloat(totals.promo_with_approval || 0) + 
                          parseFloat(totals.promo_without_approval || 0);
        
        const discountsTotal = parseFloat(totals.employee_discount || 0) + 
                              parseFloat(totals.senior_discount || 0) + 
                              parseFloat(totals.pwd_discount || 0) + 
                              parseFloat(totals.vip_discount || 0);
        
        const deductions = serviceChargeTotal + promosTotal + discountsTotal + 
                          parseFloat(totals.other_tax || 0) + 
                          parseFloat(totals.vat_exempt_sales || 0);

        // 2) Compute VAT-inclusive pre-net figure
        // const preNet = parseFloat(totals.gross_sales || 0) - deductions;
        const preNet= parseFloat(totals.net_sales || 0) + (parseFloat(totals.net_sales || 0)*.12);

        // 3) Split out VAT (12% rate)
        const VAT_RATE = 0.12;
        const vatAmount = preNet - (preNet / (1 + VAT_RATE));
        const vatExclusiveNet = preNet / (1 + VAT_RATE);

        // Update display values
        $('#net-sales-display').text(formatCurrency(preNet));
        $('#vat-amount').text(formatCurrency(vatAmount));
        $('#net-with-vat').text(formatCurrency(vatExclusiveNet));

        // Update less section
        $('#less-promo-with-approval').text(formatCurrency(totals.promo_with_approval));
        $('#less-promo-without-approval').text(formatCurrency(totals.promo_without_approval));
        $('#less-employees-discount').text(formatCurrency(totals.employee_discount));
        $('#less-approved-vip-cards').text(formatCurrency(totals.vip_discount));
        $('#less-sc-vat-exempt').text(formatCurrency(totals.vat_exempt_sales));
        $('#less-senior-pwd').text(formatCurrency(totals.senior_discount + totals.pwd_discount));
        $('#less-other-tax').text(formatCurrency(totals.other_tax));    
        $('#less-service-charge-distributed').text(formatCurrency(totals.service_charge_distributed));
        $('#less-charge-retained').text(formatCurrency(totals.service_charge_retained));

        // Update "Add:" section
        $('#sc-vat-exempt').text(formatCurrency(totals.vat_exempt_sales));
        $('#promo-without-approval').text(formatCurrency(totals.promo_without_approval));
        $('#other-tax').text(formatCurrency(totals.other_tax));
        $('#service-charge-retained').text(formatCurrency(totals.service_charge_retained));
       


        

        // Calculate final net sales subject to percentage rent
        const netSalesFinal = vatExclusiveNet + 
                            parseFloat(totals.vat_exempt_sales || 0) + 
                            parseFloat(totals.service_charge_retained || 0) + 
                            parseFloat(totals.other_tax || 0);
        
        $('#net-sales-final').text(formatCurrency(netSalesFinal));
        

        // Update less fields
        // $('#less-promo-with-approval').text(formatCurrency(totals.promo_with_approval));
        // $('#less-approved-vip-cards').text(formatCurrency(totals.vip_discount));
        // $('#less-sc-vat-exempt').text(formatCurrency(totals.vat_exempt_sales));
        // $('#less-senior-pwd').text(formatCurrency(totals.senior_discount + totals.pwd_discount));
        // $('#less-other-tax').text(formatCurrency(totals.other_tax));    
        // $('#less-service-charge-distributed').text(formatCurrency(totals.service_charge_distributed));
        // $('#less-charge-retained').text(formatCurrency(totals.service_charge_retained));
        
        // const overAllVat = parseFloat(totals.promo_with_approval || 0) + parseFloat(totals.promo_without_approval || 0) + parseFloat(totals.employee_discount || 0) 
        // + parseFloat(totals.senior_discount || 0) + parseFloat(totals.pwd_discount || 0) + parseFloat(totals.vip_discount || 0) + parseFloat(totals.service_charge_distributed || 0) 
        // + parseFloat(totals.service_charge_retained || 0) + parseFloat(totals.other_tax || 0) + parseFloat(totals.vat_exempt_sales || 0);
        // const net = parseFloat(totals.gross_sales || 0) - overAllVat;
        // $('#net-sales-display').text(formatCurrency(net));
        // Calculate and update VAT amount (12% of net sales)
        /*
          Calculate VAT amount based on net sales
          If net sales is greater than 0:
            - Divides net sales by 1.12 to get amount before VAT
            - Multiplies by 0.12 to get 12% VAT amount
          If net sales is 0 or invalid:
            - Returns 0
          
          @var float $vatAmount The calculated VAT amount
          @uses totals.net_sales The total net sales amount
        */
        // const vatAmount = parseFloat(totals.net_sales || 0) > 0 ? (parseFloat(totals.net_sales) / 1.12) * 0.12 : 0;
        // $('#vat-amount').text(formatCurrency(vatAmount));

        // Calculate net sales with VAT
        // const netWithVat = totals.net_sales - vatAmount;
        // $('#net-with-vat').text(formatCurrency(netWithVat));

        

        // Update store name in header
        // const selectedStore = $('#store-filter option:selected').text().trim();
        // $('#store-name-display').text(selectedStore === 'All Stores' ? 'All Stores' : selectedStore);

        // const scVatexempt = totals.vat_exempt_sales;
        // $('#sc-vat-exempt').text(formatCurrency(scVatexempt));

        // const otherTax = totals.other_tax;
        // $('#other-tax').text(formatCurrency(otherTax));

        // const serviceChargeRetained = totals.service_charge_retained;
        // $('#service-charge-retained').text(formatCurrency(serviceChargeRetained));

        // Calculate final net sales
        // alert(totals.promo_with_approval + totals.service_charge_retained + totals.other_tax);
        // const netSalesFinal = parseFloat(net || 0) + parseFloat(totals.vat_exempt_sales || 0) + parseFloat(totals.service_charge_retained || 0) + parseFloat(totals.other_tax || 0);
        // const netSalesFinal = netWithVat;
        // $('#net-sales-final').text(formatCurrency(netSalesFinal));
        
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
    $('#report-month').on('change', function() {
        const date = $(this).val();
        if (date) {
            const monthYear = moment(date).format('MMMM YYYY');
            $('#reportMonthDisplay').text(monthYear.toUpperCase());
        } else {
            $('#reportMonthDisplay').text('-');
        }
    });

    // Add handleExport function
    window.handleExport = function(type) {
        switch(type) {
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