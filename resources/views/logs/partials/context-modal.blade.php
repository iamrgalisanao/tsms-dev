<div class="modal fade" id="contextModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log Context Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="bg-light p-3 rounded">
          <pre><code id="contextContent" class="json"></code></pre>
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
  const content = document.getElementById('contextContent').textContent;
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
</script>
@endpush