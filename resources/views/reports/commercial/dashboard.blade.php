@extends('layouts.master')

@section('content')
<div class="container-fluid">
  <div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h3 class="m-0">Commercial Dashboard</h3>
      <div>
        <label class="text-muted mr-2">Tenant</label>
        <select id="commercial-tenant-select" class="form-control d-inline-block" style="width: 240px"></select>
      </div>
    </div>
  </div>

  <div class="row mb-3">
    @include('components.adminlte-chart', ['id' => 'chart-daily', 'key' => 'daily', 'title' => 'Daily Sales'])
    @include('components.adminlte-chart', ['id' => 'chart-weekly', 'key' => 'weekly', 'title' => 'Weekly Sales'])
  </div>

  <div class="row">
    @include('components.adminlte-chart', ['id' => 'chart-monthly', 'key' => 'monthly', 'title' => 'Monthly Sales'])
    @include('components.adminlte-chart', ['id' => 'chart-yearly', 'key' => 'yearly', 'title' => 'Yearly Sales'])
  </div>
</div>

@endsection

@section('scripts')
  <script>
    (function(){
      const $select = $('#commercial-tenant-select');
      let charts = {};

      // warn if Chart.js is not loaded - this helps debug missing library issues
      if (typeof Chart === 'undefined') {
        // do not block execution, but log so devs can check console/network
        console.warn('Chart.js not found on page: charts will not render until Chart.js is loaded.');
      }

      function initTenantSelect() {
        $.getJSON("{{ route('commercial.sales-report.tenants') }}")
          .done(function(resp){
            $select.empty();
            // support multiple response shapes: array, {data: [...]}, {rows: [...]}, {tenants: [...]}
            let list = [];
            if (Array.isArray(resp)) list = resp;
            else if (resp && Array.isArray(resp.data)) list = resp.data;
            else if (resp && Array.isArray(resp.rows)) list = resp.rows;
            else if (resp && Array.isArray(resp.tenants)) list = resp.tenants;

            if (!Array.isArray(list) || list.length === 0) {
              $select.append('<option value="">All Tenants</option>');
            } else {
              list.forEach(function(t){
                // allow tenant objects with id or tenant_id keys
                const id = t.id || t.tenant_id || t.key || '';
                const label = t.trade_name || t.customer_code || t.name || id;
                $select.append('<option value="'+id+'">'+label+'</option>');
              });
            }

            // default to first tenant (or empty = All Tenants)
            if ($select.find('option').length) {
              $select.val($select.find('option').first().val());
            }
            // initial load
            loadAllCharts();
          })
          .fail(function(jqX, status, err){
            console.warn('Failed loading tenant list for commercial dashboard', status, err);
            $select.empty().append('<option value="">All Tenants</option>');
            loadAllCharts();
          });
      }

      // AdminLTE-style bar chart helper: uses nice palette and currency tooltips
      function formatCurrency(n) {
        try {
          return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(n);
        } catch (e) {
          return n.toLocaleString();
        }
      }

      function makeBar(ctx, labels, values, opts) {
        opts = opts || {};
        const bg = opts.backgroundColor || 'rgba(54,162,235,0.6)';
        const border = opts.borderColor || 'rgba(54,162,235,1)';
        return new Chart(ctx, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [{
              label: opts.label || 'Gross Sales',
              data: values,
              backgroundColor: bg,
              borderColor: border,
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: { grid: { display: false } },
              y: {
                beginAtZero: true,
                ticks: {
                  callback: function(value) { return value >= 1000 ? (value/1000)+'k' : value; }
                }
              }
            },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const v = context.parsed.y ?? context.raw ?? 0;
                    return (context.dataset.label ? context.dataset.label + ': ' : '') + formatCurrency(Number(v));
                  }
                }
              }
            }
          }
        });
      }

      function destroyIfExists(id) {
        if (charts[id]) {
          try { charts[id].destroy(); } catch(e){}
          charts[id] = null;
        }
      }

      function loadAllCharts() {
        const tenantId = $select.val();
        loadDaily(tenantId);
        loadWeekly(tenantId);
        loadMonthly(tenantId);
        loadYearly(tenantId);
      }

      // Daily: single-value summary for today
      function loadDaily(tenantId) {
        const date = new Date().toISOString().slice(0,10);
  // prepare UI
  $('#nodata-daily').hide();
  $('#spinner-daily').show();
        $.getJSON("{{ url('commercial/reports/transactions/daily') }}", { date: date, tenant_id: tenantId })
          .done(function(resp){
            const gross = (resp.summary && resp.summary.gross_sales) ? Number(resp.summary.gross_sales) : 0;
            destroyIfExists('chart-daily');
            if (!gross || gross === 0) {
              // show no-data overlay instead of a zero-chart
              $('#nodata-daily').show();
            } else {
              $('#nodata-daily').hide();
              charts['chart-daily'] = makeBar(document.getElementById('chart-daily').getContext('2d'), [date], [gross]);
            }
          })
          .fail(function(){
            destroyIfExists('chart-daily');
            $('#nodata-daily').show();
          })
          .always(function(){
            $('#spinner-daily').hide();
          });
      }

      // Weekly: last 7 days
      function loadWeekly(tenantId) {
        const to = new Date();
        const from = new Date(); from.setDate(to.getDate()-6);
        const date_from = from.toISOString().slice(0,10);
        const date_to = to.toISOString().slice(0,10);
  // prepare UI
  $('#nodata-weekly').hide();
  $('#spinner-weekly').show();
        $.getJSON("{{ url('commercial/reports/transactions/weekly') }}", { date_from: date_from, date_to: date_to, tenant_id: tenantId })
          .done(function(resp){
            let rows = resp.rows || resp.data || resp.days || resp.period || resp || [];
            // try to handle several shapes
            if (resp && resp.summary && Array.isArray(resp.rows)) rows = resp.rows;
            if (!Array.isArray(rows)) rows = [];
            const labels = [];
            const values = [];
            rows.forEach(function(r){
              // common keys: date, day, label
              labels.push(r.date || r.day || (r.label || '').toString().slice(0,10));
              values.push(Number(r.gross_sales || r.gross || r.total_gross || 0));
            });
            destroyIfExists('chart-weekly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              $('#nodata-weekly').show();
            } else {
              $('#nodata-weekly').hide();
              charts['chart-weekly'] = makeBar(document.getElementById('chart-weekly').getContext('2d'), labels, values);
            }
          })
          .fail(function(){
            destroyIfExists('chart-weekly');
            $('#nodata-weekly').show();
          })
          .always(function(){
            $('#spinner-weekly').hide();
          });
      }

      // Monthly: current month per-day
      function loadMonthly(tenantId) {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth()+1).padStart(2,'0');
        const first = yyyy+'-'+mm+'-01';
        const last = new Date(yyyy, now.getMonth()+1, 0).toISOString().slice(0,10);
  // prepare UI
  $('#nodata-monthly').hide();
  $('#spinner-monthly').show();
        $.getJSON("{{ url('commercial/reports/transactions/monthly') }}", { date_from: first, date_to: last, tenant_id: tenantId })
          .done(function(resp){
            let rows = resp.rows || resp.data || resp.days || resp.period || resp || [];
            if (resp && resp.summary && Array.isArray(resp.rows)) rows = resp.rows;
            if (!Array.isArray(rows)) rows = [];
            const labels = [];
            const values = [];
            rows.forEach(function(r){
              labels.push(r.date || r.day || r.label || '');
              values.push(Number(r.gross_sales || r.gross || r.total_gross || 0));
            });
            destroyIfExists('chart-monthly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              $('#nodata-monthly').show();
            } else {
              $('#nodata-monthly').hide();
              charts['chart-monthly'] = makeBar(document.getElementById('chart-monthly').getContext('2d'), labels, values, {backgroundColor: 'rgba(75,192,192,0.6)', borderColor: 'rgba(75,192,192,1)'});
            }
          })
          .fail(function(){
            destroyIfExists('chart-monthly');
            $('#nodata-monthly').show();
          })
          .always(function(){
            $('#spinner-monthly').hide();
          });
      }

      // Yearly: months in current year
      function loadYearly(tenantId) {
        const now = new Date();
        const year = now.getFullYear();
        const first = year + '-01-01';
        const last = year + '-12-31';
  // prepare UI
  $('#nodata-yearly').hide();
  $('#spinner-yearly').show();
        $.getJSON("{{ url('commercial/reports/transactions/yearly') }}", { date_from: first, date_to: last, tenant_id: tenantId })
          .done(function(resp){
            const months = resp.months || resp.data || resp.rows || [];
            const labels = [];
            const values = [];
            months.forEach(function(m){
              labels.push(m.month || m.label || '');
              values.push(Number(m.gross_sales || m.gross || 0));
            });
            destroyIfExists('chart-yearly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              $('#nodata-yearly').show();
            } else {
              $('#nodata-yearly').hide();
              charts['chart-yearly'] = makeBar(document.getElementById('chart-yearly').getContext('2d'), labels, values, {backgroundColor: 'rgba(255,159,64,0.6)', borderColor: 'rgba(255,159,64,1)'});
            }
          })
          .fail(function(){
            destroyIfExists('chart-yearly');
            $('#nodata-yearly').show();
          })
          .always(function(){
            $('#spinner-yearly').hide();
          });
      }

      // Wire-up
      $(function(){
        initTenantSelect();
        $select.on('change', function(){ loadAllCharts(); });
      });
    })();
  </script>
@endsection
