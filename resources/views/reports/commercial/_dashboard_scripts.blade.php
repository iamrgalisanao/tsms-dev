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
          showChartLoading(k);
        });
      } catch(e){}
  // dashboard is aggregated across all tenants; no tenant selection required
  const tenantIdDefault = null;
      let charts = {};

      // warn if Chart.js is not loaded - AdminLTE's Chart.min.js should be present
      if (typeof Chart === 'undefined') {
        // do not block execution, but log so devs can check console/network
        console.error('AdminLTE Chart.js not found on page: ensure plugins/chart.js/Chart.min.js is included in the layout.');
      }

      // no tenant selection; charts will show aggregates for all tenants

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
  // If the data is large, scale down by millions for display. If server provided
  // scaled values, the caller will pass opts.serverScaled=true and we will not
  // re-scale client-side (we still compute tooltips to show full currency).
  const serverScaled = opts.serverScaled === true;
  const scaleFactor = serverScaled ? 1 : (axisMax && axisMax >= 1000000 ? 1000000 : 1);
  const scaledValues = scaleFactor === 1 ? values : values.map(v => (Number(v)||0) / scaleFactor);
        const datasetToUse = Object.assign({}, dataset, { data: scaledValues });

        // Chart.js v2.x options (AdminLTE default)
        if (version && parseInt(version, 10) < 3) {
          const cfg = {
            type: 'bar',
            data: { labels: labels, datasets: [datasetToUse] },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                  xAxes: [{ gridLines: { display: false } }],
                  yAxes: [
                    {
                      ticks: {
                        beginAtZero: true,
                        callback: function(value) { return serverScaled || scaleFactor === 1000000 ? Number(value).toFixed(2) : formatShortNumber(value); }
                      },
                      suggestedMax: axisMax ? (axisMax/scaleFactor) : undefined,
                      stepSize: axisMax ? (axisMax/scaleFactor/5) : undefined,
                      scaleLabel: { display: serverScaled || scaleFactor===1000000, labelString: 'Millions Php' }
                    }
                  ]
              },
              // show legend for clarity and position it at the bottom
              legend: { display: true, position: (opts.legendPosition || 'bottom'), labels: { boxWidth: 12 } },
              title: { display: !!opts.title, text: opts.title || '' },
              tooltips: {
                callbacks: {
                  label: function(tooltipItem, data) {
                    // tooltip shows the full currency plus scaled (M) when applicable
                    var raw = tooltipItem.yLabel !== undefined ? tooltipItem.yLabel : tooltipItem.value;
                    var fullVal = serverScaled ? (Number(raw) * 1000000) : (scaleFactor === 1000000 ? (Number(raw) * scaleFactor) : Number(raw));
                    var label = (dataset.label ? dataset.label + ': ' : '') + formatCurrency(Number(fullVal));
                    if (serverScaled || scaleFactor === 1000000) label += ' (' + (Number(fullVal)/1000000).toFixed(2) + 'M)';
                    else label += ' (' + formatShortNumber(Number(fullVal)) + ')';
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
          data: { labels: labels, datasets: [datasetToUse] },
            options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: { grid: { display: false } },
              y: {
                beginAtZero: true,
                ticks: {
                  callback: function(value) { return scaleFactor === 1000000 ? Number(value).toFixed(2) : formatShortNumber(value); }
                },
                suggestedMax: axisMax ? (axisMax/scaleFactor) : undefined,
                stepSize: axisMax ? (axisMax/scaleFactor/5) : undefined
              }
            },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const v = (context.parsed && context.parsed.y) != null ? context.parsed.y : (context.raw || 0);
                    const fullVal = scaleFactor === 1000000 ? (Number(v) * scaleFactor) : Number(v);
                    var lbl = (context.dataset.label ? context.dataset.label + ': ' : '') + formatCurrency(Number(fullVal));
                    if (scaleFactor === 1000000) lbl += ' (' + (Number(fullVal)/1000000).toFixed(2) + 'M)';
                    else lbl += ' (' + formatShortNumber(Number(fullVal)) + ')';
                    return lbl;
                  }
                }
              }
            }
          }
        };
        return new Chart(ctx, cfg3);
      }

      // Loading overlay helpers: toggle .is-loading on the wrapper so the shared CSS
      // overlay (spinner / no-data) fades in and blocks interactions while loading.
      function showChartLoading(key) {
        try {
          var spinner = document.getElementById('spinner-'+key);
          var nodata = document.getElementById('nodata-'+key);
          if (spinner) spinner.style.display = 'flex';
          if (nodata) nodata.style.display = 'none';
          // add class to wrapper (canvas -> .chart-wrapper)
          var canvas = document.getElementById('chart-'+key);
          if (canvas) {
            var wrap = canvas.closest('.chart-wrapper');
            if (wrap) wrap.classList.add('is-loading');
          } else if (spinner) {
            var wrap2 = spinner.closest('.chart-wrapper'); if (wrap2) wrap2.classList.add('is-loading');
          }
        } catch(e){}
      }

      function hideChartLoading(key) {
        try {
          var spinner = document.getElementById('spinner-'+key);
          var nodata = document.getElementById('nodata-'+key);
          if (spinner) spinner.style.display = 'none';
          if (nodata) nodata.style.display = 'none';
          var canvas = document.getElementById('chart-'+key);
          if (canvas) {
            var wrap = canvas.closest('.chart-wrapper');
            if (wrap) wrap.classList.remove('is-loading');
          } else if (spinner) {
            var wrap2 = spinner.closest('.chart-wrapper'); if (wrap2) wrap2.classList.remove('is-loading');
          }
        } catch(e){}
      }

      function showChartNoData(key) {
        try {
          var spinner = document.getElementById('spinner-'+key);
          var nodata = document.getElementById('nodata-'+key);
          if (spinner) spinner.style.display = 'none';
          if (nodata) nodata.style.display = 'flex';
          var canvas = document.getElementById('chart-'+key);
          if (canvas) {
            var wrap = canvas.closest('.chart-wrapper');
            if (wrap) wrap.classList.add('is-loading');
          } else if (nodata) {
            var wrap2 = nodata.closest('.chart-wrapper'); if (wrap2) wrap2.classList.add('is-loading');
          }
        } catch(e){}
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
        // scale to millions when appropriate. Honor server-provided scaled values
        // when opts.serverScaled === true (data already in millions).
        const serverScaled = opts.serverScaled === true;
        const scaleFactor = serverScaled ? 1 : (axisMax && axisMax >= 1000000 ? 1000000 : 1);
        const scaledDatasets = Array.isArray(datasets) ? datasets.map(function(d){
          const copy = Object.assign({}, d);
          if (Array.isArray(copy.data) && !serverScaled && scaleFactor === 1000000) copy.data = copy.data.map(v => (Number(v)||0) / scaleFactor);
          return copy;
        }) : datasets;

        const cfg = {
          type: 'line',
          data: { labels: labels, datasets: scaledDatasets },
            options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true, position: opts.legendPosition || 'top' }, title: { display: !!opts.title, text: opts.title || '' } },
            scales: { y: { beginAtZero: true, suggestedMax: axisMax ? (axisMax/scaleFactor) : undefined, ticks: { callback: function(v){ return serverScaled || scaleFactor===1000000 ? Number(v).toFixed(2) : formatShortNumber(v); }, stepSize: axisMax ? (axisMax/scaleFactor/5) : undefined } } }
          }
        };
        // If Chart.js v2 is present, adapt options
        const version = (window.Chart && Chart.version) ? String(Chart.version).split('.')[0] : null;
        if (version && parseInt(version,10) < 3) {
          cfg.options = {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: true, position: opts.legendPosition || 'top' },
            title: { display: !!opts.title, text: opts.title || '' },
            scales: {
              yAxes: [
                {
                  ticks: { beginAtZero: true, callback: function(v){ return serverScaled || scaleFactor===1000000 ? Number(v).toFixed(2) : formatShortNumber(v); } },
                  suggestedMax: axisMax ? (axisMax/scaleFactor) : undefined,
                  stepSize: axisMax ? (axisMax/scaleFactor/5) : undefined,
                  scaleLabel: { display: serverScaled || scaleFactor===1000000, labelString: 'Millions Php' }
                }
              ]
            }
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
        // aggregate across all tenants (no tenant_id param)
        loadDaily(null);
        loadWeekly(null);
        loadMonthly(null);
        loadYearly(null);
      }

      // Daily: single-value summary for today
      function loadDaily(tenantId) {
        const date = new Date().toISOString().slice(0,10);
  // prepare UI: show loading overlay
  showChartLoading('daily');
  $.getJSON("{{ url('commercial/reports/transactions/daily') }}", { date: date })
          .done(function(resp){
            console.debug('daily report response:', resp);
            updateDebug('daily', { ok: true, body: resp });
            const gross = (resp.summary && (typeof resp.summary.gross_sales_m !== 'undefined')) ? Number(resp.summary.gross_sales_m) : ((resp.summary && resp.summary.gross_sales) ? Number(resp.summary.gross_sales) : 0);
            destroyIfExists('chart-daily');
              if (!gross || gross === 0) {
              // show no-data overlay instead of a zero-chart
              showChartNoData('daily');
            } else {
              const serverScaled = resp.summary && (typeof resp.summary.gross_sales_m !== 'undefined');
                charts['chart-daily'] = makeBar(document.getElementById('chart-daily').getContext('2d'), [date], [gross], { serverScaled: serverScaled, label: 'Gross Sales', title: 'Daily Gross Sales', legendPosition: 'bottom' });
              hideChartLoading('daily');
            }
          })
          .fail(function(jqX, status, err){
            console.warn('daily report failed:', status, err, jqX && jqX.responseText);
            updateDebug('daily', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
            destroyIfExists('chart-daily');
            showChartNoData('daily');
          })
          .always(function(){ });
      }

      // Weekly: last 7 days
      function loadWeekly(tenantId) {
        const to = new Date();
        const from = new Date(); from.setDate(to.getDate()-6);
        const date_from = from.toISOString().slice(0,10);
        const date_to = to.toISOString().slice(0,10);
  // prepare UI: show loading overlay
  showChartLoading('weekly');
  $.getJSON("{{ url('commercial/reports/transactions/weekly') }}", { date_from: date_from, date_to: date_to })
          .done(function(resp){
            console.debug('weekly report response:', resp);
            updateDebug('weekly', { ok: true, body: resp });
            let rows = resp.rows || resp.data || resp.days || resp.period || resp || [];
            // try to handle several shapes
            if (resp && resp.summary && Array.isArray(resp.rows)) rows = resp.rows;
            if (!Array.isArray(rows)) rows = [];
            const labels = [];
            const values = [];
            // detect if server returned million-scaled fields
            const serverScaledRows = Array.isArray(rows) && rows.length && (typeof rows[0].gross_sales_m !== 'undefined');
            rows.forEach(function(r){
              // common keys: date, day, label
              labels.push(r.date || r.day || (r.label || '').toString().slice(0,10));
              values.push(Number(serverScaledRows ? (r.gross_sales_m || r.gross_m || r.gross || 0) : (r.gross_sales || r.gross || r.total_gross || 0)));
            });
            destroyIfExists('chart-weekly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              showChartNoData('weekly');
            } else {
              // If the response contains volume/count information, prefer admin-style line with two datasets
              const hasVolume = rows.some(r => (r.volume || r.count || r.tx_count));
              if (resp.labels && resp.sales) {
                // preferred shape: { labels: [...], sales: [...], volume: [...] }
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-weekly'] = makeLine(document.getElementById('chart-weekly').getContext('2d'), resp.labels, ds);
                hideChartLoading('weekly');
              } else if (hasVolume) {
                const vol = rows.map(r => Number(r.volume || r.count || r.tx_count || 0));
                const ds = [
                  { label: 'Sales', data: values, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true },
                  { label: 'Volume', data: vol, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true }
                ];
                charts['chart-weekly'] = makeLine(document.getElementById('chart-weekly').getContext('2d'), labels, ds, { serverScaled: serverScaledRows });
                hideChartLoading('weekly');
              } else {
                charts['chart-weekly'] = makeBar(document.getElementById('chart-weekly').getContext('2d'), labels, values, { serverScaled: serverScaledRows, label: 'Gross Sales', title: 'Weekly Gross Sales', legendPosition: 'bottom' });
                hideChartLoading('weekly');
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('weekly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('weekly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
            destroyIfExists('chart-weekly');
            showChartNoData('weekly');
          })
          .always(function(){ });
      }

      // Monthly: current month per-day
      function loadMonthly(tenantId) {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth()+1).padStart(2,'0');
        const first = yyyy+'-'+mm+'-01';
        const last = new Date(yyyy, now.getMonth()+1, 0).toISOString().slice(0,10);
  // prepare UI: show loading overlay
  showChartLoading('monthly');
  $.getJSON("{{ url('commercial/reports/transactions/monthly') }}", { date_from: first, date_to: last })
          .done(function(resp){
            console.debug('monthly report response:', resp);
            updateDebug('monthly', { ok: true, body: resp });
            let rows = resp.rows || resp.data || resp.days || resp.period || resp || [];
            if (resp && resp.summary && Array.isArray(resp.rows)) rows = resp.rows;
            if (!Array.isArray(rows)) rows = [];
            const labels = [];
            const values = [];
            const serverScaledRowsM = Array.isArray(rows) && rows.length && (typeof rows[0].gross_sales_m !== 'undefined');
            rows.forEach(function(r){
              labels.push(r.date || r.day || r.label || '');
              values.push(Number(serverScaledRowsM ? (r.gross_sales_m || r.gross || 0) : (r.gross_sales || r.gross || r.total_gross || 0)));
            });
            destroyIfExists('chart-monthly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              showChartNoData('monthly');
            } else {
              // monthly: prefer line chart if dataset contains sales + volume shape
              if (resp.labels && resp.sales) {
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-monthly'] = makeLine(document.getElementById('chart-monthly').getContext('2d'), resp.labels, ds);
                hideChartLoading('monthly');
              } else {
                charts['chart-monthly'] = makeBar(document.getElementById('chart-monthly').getContext('2d'), labels, values, {backgroundColor: 'rgba(75,192,192,0.6)', borderColor: 'rgba(75,192,192,1)', serverScaled: serverScaledRowsM, label: 'Gross Sales', title: 'Monthly Gross Sales', legendPosition: 'bottom'});
                hideChartLoading('monthly');
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('monthly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('monthly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
            destroyIfExists('chart-monthly');
            showChartNoData('monthly');
          })
          .always(function(){ });
      }

      // Yearly: months in current year
      function loadYearly(tenantId) {
        const now = new Date();
        const year = now.getFullYear();
        const first = year + '-01-01';
        const last = year + '-12-31';
  // prepare UI: show loading overlay
  showChartLoading('yearly');
  $.getJSON("{{ url('commercial/reports/transactions/yearly') }}", { date_from: first, date_to: last })
          .done(function(resp){
            console.debug('yearly report response:', resp);
            updateDebug('yearly', { ok: true, body: resp });
            const months = resp.months || resp.data || resp.rows || [];
            const labels = [];
            const values = [];
            const serverScaledMonths = Array.isArray(months) && months.length && (typeof months[0].gross_sales_m !== 'undefined');
            months.forEach(function(m){
              labels.push(m.month || m.label || '');
              values.push(Number(serverScaledMonths ? (m.gross_sales_m || m.gross || 0) : (m.gross_sales || m.gross || 0)));
            });
            destroyIfExists('chart-yearly');
            const total = values.reduce(function(a,b){ return a + (isNaN(b) ? 0 : Number(b)); }, 0);
            if (!values.length || total === 0) {
              showChartNoData('yearly');
            } else {
              // yearly: if response provides sales + volume arrays, show admin-style line chart
              if (resp.labels && resp.sales) {
                const ds = [{ label: 'Sales', data: resp.sales, borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true }];
                if (Array.isArray(resp.volume)) ds.push({ label: 'Volume', data: resp.volume, borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,0.1)', fill: true });
                charts['chart-yearly'] = makeLine(document.getElementById('chart-yearly').getContext('2d'), resp.labels, ds);
                hideChartLoading('yearly');
              } else {
                charts['chart-yearly'] = makeBar(document.getElementById('chart-yearly').getContext('2d'), labels, values, {backgroundColor: 'rgba(255,159,64,0.6)', borderColor: 'rgba(255,159,64,1)', serverScaled: serverScaledMonths, label: 'Gross Sales', title: 'Yearly Gross Sales', legendPosition: 'bottom'});
                hideChartLoading('yearly');
              }
            }
          })
          .fail(function(jqX, status, err){
            console.warn('yearly report failed:', status, err, jqX && jqX.responseText);
            updateDebug('yearly', { ok: false, status: jqX && jqX.status, body: jqX && jqX.responseText });
            destroyIfExists('chart-yearly');
            showChartNoData('yearly');
          })
          .always(function(){ });
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
        // load charts aggregated for all tenants
        loadAllCharts();
      });
    })();
  </script>
