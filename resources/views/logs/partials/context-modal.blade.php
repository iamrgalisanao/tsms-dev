<div class="modal fade" id="contextModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log Context Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
</script>
@endpush