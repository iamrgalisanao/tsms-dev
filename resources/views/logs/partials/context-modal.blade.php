<div class="modal fade" id="contextModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="contextModalTitle">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="contextModalTitle">Log Context Details</h5>
        <!-- Close button compatible with Bootstrap 4 and 5 -->
        <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close">
          <span class="sr-only">Close</span>
        </button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" id="contextTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="json-tab" data-bs-toggle="tab" data-bs-target="#jsonView" type="button" role="tab" aria-controls="jsonView" aria-selected="true">JSON</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="html-tab" data-bs-toggle="tab" data-bs-target="#htmlView" type="button" role="tab" aria-controls="htmlView" aria-selected="false">Details</button>
          </li>
        </ul>
        <div class="tab-content" id="contextTabContent">
          <div class="tab-pane fade show active" id="jsonView" role="tabpanel" aria-labelledby="json-tab">
            <div class="bg-light p-3 rounded">
              <pre><code id="contextContent" class="json"></code></pre>
            </div>
          </div>
          <div class="tab-pane fade" id="htmlView" role="tabpanel" aria-labelledby="html-tab">
            <div id="contextHtmlContent"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="copyContext()">
          <i class="fas fa-copy me-1"></i>Copy
        </button>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
function copyContext() {
  const activeTab = document.querySelector('#contextTabs .nav-link.active').id;
  let content = '';
  if (activeTab === 'json-tab') {
    content = document.getElementById('contextContent').textContent;
  } else {
    content = document.getElementById('contextHtmlContent').innerText;
  }
  navigator.clipboard.writeText(content).then(() => {
    // Show copied notification
    const btn = document.querySelector('.modal-footer .btn-primary');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    setTimeout(() => btn.innerHTML = originalText, 2000);
  });
}

function formatJson(data) {
  try {
    return JSON.stringify(JSON.parse(data), null, 2);
  } catch (e) {
    return data;
  }
}

// Example usage: set both JSON and HTML details
function setLogDetails(json, html) {
  document.getElementById('contextContent').textContent = formatJson(json);
  document.getElementById('contextHtmlContent').innerHTML = html;
}

// Accessibility/focus management for the modal to avoid aria-hidden focus warnings
(function() {
  var modal = document.getElementById('contextModal');
  if (!modal) return;

  var previouslyFocused = null;

  function contains(parent, node) {
    try { return parent && node && parent.contains(node); } catch (e) { return false; }
  }

  function onShow() {
    // Remember what had focus before opening
    previouslyFocused = document.activeElement;
    // Move initial focus inside modal (close button preferred)
    var closeBtn = modal.querySelector('.btn-close, [data-dismiss="modal"], [data-bs-dismiss="modal"]');
    if (closeBtn && typeof closeBtn.focus === 'function') {
      try { closeBtn.focus({ preventScroll: true }); } catch (e) {}
    }
  }

  function onHide() {
    // If a focused element is inside the modal, blur it before aria-hidden is applied
    var active = document.activeElement;
    if (contains(modal, active) && typeof active.blur === 'function') {
      try { active.blur(); } catch (e) {}
    }
  }

  function onHidden() {
    // Restore focus to the element that opened the modal (if still in DOM), else fallback
    if (previouslyFocused && document.contains(previouslyFocused) && typeof previouslyFocused.focus === 'function') {
      try { previouslyFocused.focus({ preventScroll: true }); } catch (e) {}
    } else {
      try { (document.querySelector('main, [tabindex="-1"]') || document.body).focus({ preventScroll: true }); } catch (e) {}
    }
    previouslyFocused = null;
  }

  // Wire events for both Bootstrap 4 (jQuery) and Bootstrap 5
  if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
    $('#contextModal')
      .on('show.bs.modal', onShow)
      .on('hide.bs.modal', onHide)
      .on('hidden.bs.modal', onHidden);
  } else if (window.bootstrap && window.bootstrap.Modal) {
    modal.addEventListener('show.bs.modal', onShow);
    modal.addEventListener('hide.bs.modal', onHide);
    modal.addEventListener('hidden.bs.modal', onHidden);
  }
})();
</script>
@endpush