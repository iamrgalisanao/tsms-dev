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

@push('scripts')
  <script>
    // Ensure this dashboard uses the AdminLTE-bundled Chart.js runtime (v2) that is
    // loaded in the master layout via plugins/chart.js/Chart.min.js. We avoid
    // importing Chart.js via the Vite bundle so there is a single global Chart.
    (function(){
      // very-visible load marker to help debug whether inline scripts execute
      try { console.log('[commercial] dashboard inline script loaded'); } catch(e) {}
      // show initial spinners immediately so users see loading state before XHRs
      try {
        ['daily','weekly','monthly','yearly'].forEach(function(k){
          var s = document.getElementById('spinner-'+k); if (s) s.style.display = 'flex';
          var n = document.getElementById('nodata-'+k); if (n) n.style.display = 'none';
        });
      } catch(e){}
      const $select = $('#commercial-tenant-select');
      let charts = {};

      // warn if Chart.js is not loaded - AdminLTE's Chart.min.js should be present
      if (typeof Chart === 'undefined') {
        // do not block execution, but log so devs can check console/network
        console.error('AdminLTE Chart.js not found on page: ensure plugins/chart.js/Chart.min.js is included in the layout.');
      }

      function initTenantSelect() {
        $.getJSON("{{ route('commercial.sales-report.tenants') }}")
          .done(function(resp){
            console.debug('commercial.tenants response:', resp);
            updateDebug('tenants', { ok: true, body: resp });
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
            console.warn('Failed loading tenant list for commercial dashboard', status, err, jqX && jqX.responseText);
            updateDebug('tenants', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
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

      // Short number formatter: show thousands as 'k' and millions as 'M'
      function formatShortNumber(n) {
        try {
          const num = Number(n) || 0;
          const abs = Math.abs(num);
          if (abs >= 1000000) {
            // one decimal when helpful
            return (Math.round((num / 1000000) * 10) / 10) + 'M';
          }
          if (abs >= 1000) {
            return (Math.round((num / 1000) * 10) / 10) + 'k';
          }
          return String(num);
        } catch (e) {
          return String(n);
        }
      }

      function makeBar(ctx, labels, values, opts) {
        opts = opts || {};
        // support both Chart.js v2 (AdminLTE bundled) and v3+ (vite bundle)
        const version = (window.Chart && Chart.version) ? String(Chart.version).split('.')[0] : null;
        // create optional gradient if requested
        let background = opts.backgroundColor || 'rgba(54,162,235,0.6)';
        try {
          if (opts.useGradient && ctx && ctx.createLinearGradient) {
            const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height || 200);
            g.addColorStop(0, 'rgba(54,162,235,0.85)');
            g.addColorStop(1, 'rgba(54,162,235,0.25)');
            background = g;
          }
        } catch (e) {
          // ignore gradient errors
        }

        const dataset = {
          label: opts.label || 'Gross Sales',
          data: values,
          backgroundColor: background,
          borderColor: opts.borderColor || 'rgba(54,162,235,1)',
          borderWidth: 1
        };

        // Compute a "nice" max for the Y axis to avoid one large value
        // stretching the chart. Uses a 1-2-2.5-5-10 series algorithm.
        function niceMax(arr) {
          if (!Array.isArray(arr) || arr.length === 0) return null;
          const max = arr.reduce((a,b) => Math.max(a, Number(b) || 0), 0);
          if (!isFinite(max) || max <= 0) return null;
          const exp = Math.floor(Math.log10(max));
          const mag = Math.pow(10, exp);
          const normalized = max / mag;
          let niceNorm = 1;
          if (normalized <= 1) niceNorm = 1;
          else if (normalized <= 2) niceNorm = 2;
          else if (normalized <= 2.5) niceNorm = 2.5;
          else if (normalized <= 5) niceNorm = 5;
          else niceNorm = 10;
          return niceNorm * mag;
        }

        const axisMax = niceMax(values);

        // Chart.js v2.x options (AdminLTE default)
        if (version && parseInt(version, 10) < 3) {
          const cfg = {
            type: 'bar',
            data: { labels: labels, datasets: [dataset] },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                  xAxes: [{ gridLines: { display: false } }],
                  yAxes: [{ ticks: { beginAtZero: true, callback: function(value) { return formatShortNumber(value); }, suggestedMax: axisMax, stepSize: axisMax ? axisMax/5 : undefined } }]
              },
              legend: { display: false },
              tooltips: {
                callbacks: {
                  label: function(tooltipItem, data) {
                    var v = tooltipItem.yLabel !== undefined ? tooltipItem.yLabel : tooltipItem.value;
                    var label = (dataset.label ? dataset.label + ': ' : '') + formatCurrency(Number(v));
                    // also show abbreviated value in tooltip for quick reading
                    label += ' (' + formatShortNumber(Number(v)) + ')';
                    return label;
                  }
                }
              }
            }
          };
          return new Chart(ctx, cfg);
        }

        // Chart.js v3+ options
        const cfg3 = {
          type: 'bar',
          data: { labels: labels, datasets: [dataset] },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: { grid: { display: false } },
              y: { beginAtZero: true, ticks: { callback: function(value) { return formatShortNumber(value); }, suggestedMax: axisMax, stepSize: axisMax ? axisMax/5 : undefined } }
            },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const v = (context.parsed && context.parsed.y) != null ? context.parsed.y : (context.raw || 0);
                    var lbl = (context.dataset.label ? context.dataset.label + ': ' : '') + formatCurrency(Number(v));
                    lbl += ' (' + formatShortNumber(Number(v)) + ')';
                    return lbl;
                  }
                }
              }
            }
          }
        };
        return new Chart(ctx, cfg3);
      }

      // Create a line chart similar to admin dashboard: supports multiple datasets
      function makeLine(ctx, labels, datasets, opts) {
        opts = opts || {};
        // compute max across datasets
        const allVals = [];
        if (Array.isArray(datasets)) {
          datasets.forEach(function(d){ if (Array.isArray(d.data)) d.data.forEach(function(v){ allVals.push(Number(v)||0); }); });
        }
        function niceMaxFromArray(arr) {
          if (!arr || arr.length === 0) return null;
          const max = arr.reduce((a,b)=>Math.max(a,b), 0);
          if (!isFinite(max) || max <= 0) return null;
          const exp = Math.floor(Math.log10(max));
          const mag = Math.pow(10, exp);
          const normalized = max / mag;
          let niceNorm = 1;
          if (normalized <= 1) niceNorm = 1;
          else if (normalized <= 2) niceNorm = 2;
          else if (normalized <= 2.5) niceNorm = 2.5;
          else if (normalized <= 5) niceNorm = 5;
          else niceNorm = 10;
          return niceNorm * mag;
        }
        const axisMax = niceMaxFromArray(allVals);

        const cfg = {
          type: 'line',
          data: { labels: labels, datasets: datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: opts.legendPosition || 'top' } },
            scales: { y: { beginAtZero: true, suggestedMax: axisMax, ticks: { callback: function(value) { return formatShortNumber(value); }, stepSize: axisMax ? axisMax/5 : undefined } } }
          }
        };
        // If Chart.js v2 is present, adapt options
        const version = (window.Chart && Chart.version) ? String(Chart.version).split('.')[0] : null;
        if (version && parseInt(version,10) < 3) {
          cfg.options = {
            responsive: true,
            maintainAspectRatio: false,
            legend: { position: opts.legendPosition || 'top' },
            scales: { yAxes: [{ ticks: { beginAtZero: true, callback: function(value) { return formatShortNumber(value); }, suggestedMax: axisMax, stepSize: axisMax ? axisMax/5 : undefined } }] }
          };
        }
        return new Chart(ctx, cfg);
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
            console.debug('daily report response:', resp);
            updateDebug('daily', { ok: true, body: resp });
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
          .fail(function(jqX, status, err){
            console.warn('daily report failed:', status, err, jqX && jqX.responseText);
            updateDebug('daily', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
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
            console.debug('weekly report response:', resp);
            updateDebug('weekly', { ok: true, body: resp });
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
              // If the response contains volume/count information, prefer admin-style line with two datasets
              const hasVolume = rows.some(r => (r.volume || r.count || r.tx_count));
              if (resp.labels && resp.sales) {
                // preferred shape: { labels: [...], sales: [...], volume: [...] }
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-weekly'] = makeLine(document.getElementById('chart-weekly').getContext('2d'), resp.labels, ds);
              } else if (hasVolume) {
                const vol = rows.map(r => Number(r.volume || r.count || r.tx_count || 0));
                const ds = [
                  { label: 'Sales', data: values, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true },
                  { label: 'Volume', data: vol, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true }
                ];
                charts['chart-weekly'] = makeLine(document.getElementById('chart-weekly').getContext('2d'), labels, ds);
              } else {
                charts['chart-weekly'] = makeBar(document.getElementById('chart-weekly').getContext('2d'), labels, values);
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('weekly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('weekly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
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
            console.debug('monthly report response:', resp);
            updateDebug('monthly', { ok: true, body: resp });
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
              // monthly: prefer line chart if dataset contains sales + volume shape
              if (resp.labels && resp.sales) {
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-monthly'] = makeLine(document.getElementById('chart-monthly').getContext('2d'), resp.labels, ds);
              } else {
                charts['chart-monthly'] = makeBar(document.getElementById('chart-monthly').getContext('2d'), labels, values, {backgroundColor: 'rgba(75,192,192,0.6)', borderColor: 'rgba(75,192,192,1)'});
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('monthly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('monthly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
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
            console.debug('yearly report response:', resp);
            updateDebug('yearly', { ok: true, body: resp });
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
              // yearly: if response provides sales + volume arrays, show admin-style line chart
              if (resp.labels && resp.sales) {
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-yearly'] = makeLine(document.getElementById('chart-yearly').getContext('2d'), resp.labels, ds);
              } else {
                charts['chart-yearly'] = makeBar(document.getElementById('chart-yearly').getContext('2d'), labels, values, {backgroundColor: 'rgba(255,159,64,0.6)', borderColor: 'rgba(255,159,64,1)'});
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('yearly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('yearly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
            destroyIfExists('chart-yearly');
            $('#nodata-yearly').show();
          })
          .always(function(){
            $('#spinner-yearly').hide();
          });
      }

      // Wire-up
      $(function(){
        // add lightweight in-page debug panel for troubleshooting XHRs
        const dbg = $('<pre id="chart-debug" style="display:none; position:fixed; right:10px; bottom:10px; z-index:9999; max-width:420px; max-height:60vh; overflow:auto; background:#fff; border:1px solid #ddd; padding:8px; font-size:12px; box-shadow:0 6px 18px rgba(0,0,0,0.08);"></pre>');
        $('body').append(dbg);
        window.toggleChartDebug = function(){ $('#chart-debug').toggle(); };
        window.updateChartDebug = function(obj){ $('#chart-debug').text(JSON.stringify(obj, null, 2)); };

        // internal state for debug info
        window._chartDebugState = { chartVersion: (window.Chart && Chart.version) ? Chart.version : null };
        function updateDebug(key, info){ window._chartDebugState[key] = info; window._chartDebugState.chartVersion = (window.Chart && Chart.version) ? Chart.version : null; updateChartDebug(window._chartDebugState); }
        window.updateDebug = updateDebug;
        initTenantSelect();
        $select.on('change', function(){ loadAllCharts(); });
      });
    })();
  </script>
@endpush
