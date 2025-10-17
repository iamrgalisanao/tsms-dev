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
    <tbody>
      @php
        $events = $submissionEvents ?? collect();
      @endphp
      @forelse ($events as $event)
        <tr>
          <td>{{ optional($event->occurred_at ?? $event->created_at)->format('Y-m-d H:i:s') }}</td>
          <td class="text-monospace">{{ $event->submission_uuid }}</td>
          <td>
            @php $status = strtoupper($event->status ?? ''); @endphp
            <span class="badge {{ $status === 'REJECTED' ? 'bg-danger' : ($status === 'COMPLETED' ? 'bg-success' : 'bg-secondary') }}">{{ $status }}</span>
          </td>
          <td>{{ $event->tenant_id }}</td>
          <td>{{ $event->terminal_id }}</td>
          <td>{{ $event->transaction_count ?? '-' }}</td>
          <td>
            @if(!empty($event->reason_code))
              <span title="{{ is_array($event->reason_details) ? json_encode($event->reason_details) : (string) $event->reason_details }}">{{ $event->reason_code }}</span>
            @else
              —
            @endif
          </td>
          <td>
            <button class="btn btn-sm btn-outline-primary view-submission-items" data-submission="{{ $event->submission_uuid }}">
              Details
            </button>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="text-center text-muted">No submission events found</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

@push('scripts')
<script>
// Load per-item details via API and show in the existing context modal
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
        if (!items.length) {
          $('#contextModal .modal-body').html('<div class="text-center text-muted">No itemized events for this submission.</div>');
          return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm table-striped">\
          <thead><tr><th>Time</th><th>Transaction</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
        items.forEach(it => {
          const ts = (it.occurred_at || it.created_at || '').toString().replace('T',' ').replace('Z','');
          const st = (it.status || '').toUpperCase();
          const badge = st === 'FAILED' ? 'bg-danger' : (st === 'QUEUED' ? 'bg-warning' : 'bg-secondary');
          const reason = (it.reason_code || '—') + (it.reason_details ? ' · ' + JSON.stringify(it.reason_details) : '');
          html += `<tr><td>${ts}</td><td class="text-monospace">${it.transaction_id || '—'}</td><td><span class="badge ${badge}">${st}</span></td><td>${reason}</td></tr>`;
        });
        html += '</tbody></table></div>';
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