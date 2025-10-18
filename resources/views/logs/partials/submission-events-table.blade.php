<div class="mb-3">
  <div class="row align-items-end">
    <div class="col-md-3 col-sm-6 mb-2">
      <div class="form-group mb-0">
        <label for="filterTenant" class="mb-1">Tenant</label>
        <select id="filterTenant" class="form-control form-control-sm"></select>
      </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-2">
      <div class="form-group mb-0">
        <label for="filterTerminal" class="mb-1">Terminal</label>
        <div class="input-group input-group-sm">
          <div class="input-group-prepend">
            <span class="input-group-text"><i class="fas fa-terminal"></i></span>
          </div>
          <input id="filterTerminal" type="number" class="form-control" placeholder="e.g. 101">
        </div>
      </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-2">
      <div class="form-group mb-0">
        <label for="filterStatus" class="mb-1">Status</label>
        <select id="filterStatus" class="form-control form-control-sm">
          <option value="">Any</option>
          <option value="RECEIVED">RECEIVED</option>
          <option value="COMPLETED">COMPLETED</option>
          <option value="REJECTED">REJECTED</option>
        </select>
      </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-2">
      <div class="form-group mb-0">
        <label for="filterFrom" class="mb-1">From</label>
        <div class="input-group input-group-sm">
          <div class="input-group-prepend">
            <span class="input-group-text"><i class="far fa-calendar-alt"></i></span>
          </div>
          <input id="filterFrom" type="date" class="form-control">
        </div>
      </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-2">
      <div class="form-group mb-0">
        <label for="filterTo" class="mb-1">To</label>
        <div class="input-group input-group-sm">
          <div class="input-group-prepend">
            <span class="input-group-text"><i class="far fa-calendar-alt"></i></span>
          </div>
          <input id="filterTo" type="date" class="form-control">
        </div>
      </div>
    </div>
    <div class="col-md-1 col-sm-6 mb-2">
      <div class="btn-group btn-group-sm d-flex" role="group" aria-label="Filters actions">
        <button id="applySubmissionFilters" class="btn btn-primary">Apply</button>
        <button id="resetSubmissionFilters" class="btn btn-outline-secondary">Reset</button>
      </div>
    </div>
  </div>
</div>

<div id="submissionSummary" class="mb-2" aria-live="polite"></div>

<div class="table-responsive">
  <table id="submissionTable" class="table table-striped table-hover align-middle">
    <thead>
      <tr>
        <th>Time</th>
        <th>Submission UUID</th>
        <th>Status</th>
        <th>Tenant</th>
        <th>Terminal</th>
        <th>Tx Count</th>
        <th>Reason</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>
<div id="submissionPager" class="d-flex justify-content-between align-items-center mt-2">
  <div class="text-muted small" id="submissionPageInfo"></div>
  <nav>
    <ul class="pagination pagination-sm mb-0" id="submissionPagination"></ul>
  </nav>
  <div>
    <select id="submissionPerPage" class="form-control form-control-sm d-inline-block w-auto">
      <option value="10">10</option>
      <option value="15" selected>15</option>
      <option value="25">25</option>
      <option value="50">50</option>
    </select>
  </div>
  </div>

@push('scripts')
<script>
let submissionFilters = {
  tenant_id: '', terminal_id: '', status: '', date_from: '', date_to: '', page: 1, per_page: 15
};

function renderSubmissionSummary(summary) {
  const { total=0, received=0, completed=0, rejected=0 } = summary || {};
  const html = '<div class="d-flex align-items-center flex-wrap">'
    + `<span class="badge bg-secondary mr-2 mb-1" aria-label="Total events">Total: ${total}</span>`
    + `<span class="badge bg-info mr-2 mb-1" aria-label="Received">RECEIVED: ${received}</span>`
    + `<span class="badge bg-success mr-2 mb-1" aria-label="Completed">COMPLETED: ${completed}</span>`
    + `<span class="badge bg-danger mb-1" aria-label="Rejected">REJECTED: ${rejected}</span>`
    + '</div>';
  $('#submissionSummary').html(html);
}

function renderSubmissionRows(events) {
  const $tbody = $('#submissionTable tbody');
  if (!events || events.length === 0) {
    $tbody.html('<tr><td colspan="8" class="text-center text-muted">No submission events found</td></tr>');
    return;
  }
  const rows = events.map(ev => {
    const occurred = (ev.occurred_at || ev.created_at || '').toString().replace('T',' ').replace('Z','');
    const status = (ev.status || '').toUpperCase();
    const badge = status==='REJECTED'?'bg-danger':(status==='COMPLETED'?'bg-success':'bg-secondary');
    const reason = ev.reason_code ? `<span title="${(typeof ev.reason_details==='object'?JSON.stringify(ev.reason_details): (ev.reason_details||''))}">${ev.reason_code}</span>` : '—';
    return `<tr>
      <td>${occurred}</td>
      <td class="text-monospace">${ev.submission_uuid}</td>
      <td><span class="badge ${badge}">${status}</span></td>
      <td>${ev.tenant_id ?? ''}</td>
      <td>${ev.terminal_id ?? ''}</td>
      <td>${ev.transaction_count ?? '-'}</td>
      <td>${reason}</td>
      <td>
        <button class="btn btn-sm btn-outline-primary view-submission-items" data-submission="${ev.submission_uuid}">Details</button>
      </td>
    </tr>`;
  }).join('');
  $tbody.html(rows);
}

function renderPager(pagination) {
  const { current_page=1, last_page=1, total=0, per_page=15 } = pagination || {};
  $('#submissionPageInfo').text(`Page ${current_page} of ${last_page} · ${total} total`);
  const $ul = $('#submissionPagination');
  $ul.empty();
  function li(page, label, disabled=false, active=false) {
    return `<li class="page-item ${disabled?'disabled':''} ${active?'active':''}"><a class="page-link" href="#" data-page="${page}">${label}</a></li>`;
  }
  $ul.append(li(Math.max(1, current_page-1), '«', current_page<=1));
  const start = Math.max(1, current_page-2);
  const end = Math.min(last_page, current_page+2);
  for (let p=start; p<=end; p++) { $ul.append(li(p, p, false, p===current_page)); }
  $ul.append(li(Math.min(last_page, current_page+1), '»', current_page>=last_page));
}

function loadSubmissionOptionsOnce(opts) {
  // Populate tenant options if provided
  if (opts && opts.tenants) {
    const $sel = $('#filterTenant');
    $sel.empty().append('<option value="">Any</option>');
    opts.tenants.forEach(t => $sel.append(`<option value="${t.id}">${t.trade_name ?? ('Tenant #'+t.id)}</option>`));
  }
}

function fetchSubmissionEvents() {
  const params = { ...submissionFilters };
  $('#submissionTable tbody').html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></td></tr>');
  $.ajax({
    url: '{{ route('log-viewer.submission.events') }}',
    method: 'GET',
    data: params,
    success: function(resp) {
      loadSubmissionOptionsOnce(resp.options);
      renderSubmissionSummary(resp.summary);
      renderSubmissionRows(resp.data);
      renderPager(resp.pagination);
    },
    error: function() {
      $('#submissionTable tbody').html('<tr><td colspan="8" class="text-danger">Failed to load submission events.</td></tr>');
    }
  });
}

// Events
$(document).on('click', '#submissionPagination .page-link', function(e) {
  e.preventDefault();
  const page = parseInt($(this).data('page'), 10);
  if (!isNaN(page)) { submissionFilters.page = page; fetchSubmissionEvents(); }
});

$('#submissionPerPage').on('change', function() {
  submissionFilters.per_page = parseInt($(this).val(), 10) || 15;
  submissionFilters.page = 1;
  fetchSubmissionEvents();
});

$('#applySubmissionFilters').on('click', function() {
  submissionFilters.tenant_id = $('#filterTenant').val() || '';
  submissionFilters.terminal_id = $('#filterTerminal').val() || '';
  submissionFilters.status = $('#filterStatus').val() || '';
  submissionFilters.date_from = $('#filterFrom').val() || '';
  submissionFilters.date_to = $('#filterTo').val() || '';
  submissionFilters.page = 1;
  fetchSubmissionEvents();
});

$('#resetSubmissionFilters').on('click', function() {
  $('#filterTenant').val('');
  $('#filterTerminal').val('');
  $('#filterStatus').val('');
  $('#filterFrom').val('');
  $('#filterTo').val('');
  submissionFilters = { tenant_id: '', terminal_id: '', status: '', date_from: '', date_to: '', page: 1, per_page: submissionFilters.per_page };
  fetchSubmissionEvents();
});

// Lazy-load when tab becomes visible; also allow manual refresh if already active
$(document).on('shown.bs.tab', 'a[data-bs-toggle="tab"]', function (e) {
  const target = $(e.target).attr('href');
  if (target === '#submission') { fetchSubmissionEvents(); }
});

// Initial load if tab is already active on render
if ($('a[href="#submission"]').hasClass('active') || $('#submission').hasClass('show active')) {
  fetchSubmissionEvents();
}
$(document).on('click', '.view-submission-items', function() {
  const submission = $(this).data('submission');
  $('#contextModal .modal-body').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>');
  // Open modal (Bootstrap 4 jQuery plugin or Bootstrap 5 class API)
  try {
    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
      $('#contextModal').modal('show');
    } else if (window.bootstrap && window.bootstrap.Modal) {
      const el = document.getElementById('contextModal');
      const modal = window.bootstrap.Modal.getOrCreateInstance ? window.bootstrap.Modal.getOrCreateInstance(el) : new window.bootstrap.Modal(el);
      modal.show();
    }
  } catch (e) {
    // no-op; body content will still render
  }
  $.ajax({
    url: '/log-viewer/submission-items/' + submission,
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    success: function(resp) {
      try {
        const items = resp.data || resp.items || [];
        const event = resp.event || null;
        if (!items.length) {
          // Show submission summary when no per-item records exist
          if (event) {
            const occurred = (event.occurred_at || event.created_at || '').toString().replace('T',' ').replace('Z','');
            const status = (event.status || '').toUpperCase();
            const reason = (event.reason_code || '—');
            const details = event.reason_details ? JSON.stringify(event.reason_details, null, 2) : null;
            // Compute Horizon and Logs links (best-effort)
            const hzBase = (typeof HORIZON_BASE_URL !== 'undefined' && HORIZON_BASE_URL) ? HORIZON_BASE_URL : ("{{ rtrim(config('horizon.domain') ?? '', '/') }}/{{ trim(config('horizon.path'), '/') }}".replace(/\/+$/,''));
            const horizonLink = hzBase ? `${hzBase}` : '#';
            const logsLink = `/log-viewer?tab=audit&search=${encodeURIComponent(event.submission_uuid || '')}`;
            let html = '<div class="mb-3">'
              + `<div><strong>Submission UUID:</strong> <span class="text-monospace">${event.submission_uuid}</span></div>`
              + `<div><strong>Status:</strong> <span class="badge ${status==='REJECTED'?'bg-danger':(status==='COMPLETED'?'bg-success':'bg-secondary')}">${status}</span></div>`
              + `<div><strong>Occurred:</strong> ${occurred}</div>`
              + `<div><strong>Tenant / Terminal:</strong> ${event.tenant_id} / ${event.terminal_id}</div>`
              + `<div><strong>Transaction Count:</strong> ${event.transaction_count ?? '-'}</div>`
              + `<div><strong>Reason:</strong> ${reason}</div>`;
            if (details) {
              html += `<div class="mt-2"><strong>Reason Details:</strong><pre class="bg-light p-2 rounded">${details}</pre></div>`;
            }
            html += `<div class="mt-3 d-flex gap-2">
              <a href="${horizonLink}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-tachometer-alt me-1"></i>Open Horizon</a>
              <a href="${logsLink}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-search me-1"></i>Search Logs</a>
            </div>`;
            html += '</div>';
            $('#contextModal .modal-body').html(html);
          } else {
            $('#contextModal .modal-body').html('<div class="text-center text-muted">No itemized events for this submission.</div>');
          }
          return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm table-striped">\
          <thead><tr><th>Time</th><th>Transaction</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
        items.forEach(it => {
          const ts = (it.occurred_at || it.created_at || '').toString().replace('T',' ').replace('Z','');
          const st = (it.status || '').toUpperCase();
          const badge = st === 'FAILED' ? 'bg-danger' : (st === 'QUEUED' ? 'bg-warning' : 'bg-secondary');
          const reason = (it.reason_code || '—') + (it.reason_details ? ' · ' + JSON.stringify(it.reason_details) : '');
          html += `<tr><td>${ts}</td><td class=\"text-monospace\">${it.transaction_id || '—'}</td><td><span class=\"badge ${badge}\">${st}</span></td><td>${reason}</td></tr>`;
        });
        html += '</tbody></table></div>';
        // Actions area below table
        const hzBase = (typeof HORIZON_BASE_URL !== 'undefined' && HORIZON_BASE_URL) ? HORIZON_BASE_URL : ("{{ rtrim(config('horizon.domain') ?? '', '/') }}/{{ trim(config('horizon.path'), '/') }}".replace(/\/+$/,''));
        const horizonLink = hzBase ? `${hzBase}` : '#';
        const logsLink = `/log-viewer?tab=audit&search=${encodeURIComponent(resp.submission_uuid || '')}`;
        html += `<div class="mt-3 d-flex gap-2">
          <a href="${horizonLink}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-tachometer-alt me-1"></i>Open Horizon</a>
          <a href="${logsLink}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-search me-1"></i>Search Logs</a>
        </div>`;
        $('#contextModal .modal-body').html(html);
      } catch (e) {
        $('#contextModal .modal-body').html('<div class="text-danger">Error rendering items.</div>');
      }
    },
    error: function(xhr) {
      $('#contextModal .modal-body').html('<div class="text-danger">Failed to load submission items.</div>');
    }
  });
});
</script>
@endpush