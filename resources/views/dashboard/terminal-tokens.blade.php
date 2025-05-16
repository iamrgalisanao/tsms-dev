@extends('layouts.app')

@section('content')
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5>POS Terminal Tokens</h5>
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      {{ session('success') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif
  </div>
  <div class="card-body">
    <div class="alert alert-info mb-4">
      <h5>About Terminal Tokens</h5>
      <p>Terminal tokens in TSMS are used to securely authenticate Point-of-Sale (POS) terminals when transmitting sales
        transactions via API.</p>
      <p>Each token is a <strong>JWT (JSON Web Token)</strong> that must be included in the Authorization header:</p>
      <code>Authorization: Bearer &lt;JWT_TOKEN&gt;</code>
    </div>

    @if(session('token_info'))
    <div class="alert alert-success mb-4">
      <h5>New JWT Token Generated</h5>
      <p><strong>Terminal ID:</strong> {{ session('token_info.terminal_id') }}</p>
      <p><strong>Token Type:</strong> {{ session('token_info.token_type') }}</p>
      <p><strong>Access Token:</strong></p>
      <pre class="bg-light p-2 border rounded"><code>{{ session('token_info.access_token') }}</code></pre>
      <p><strong>Expires:</strong> {{ session('token_info.expires_at') }}</p>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> Store this token securely! It will not be displayed again.
      </div>
    </div>
    @endif

    @if(count($terminalTokens) > 0)
    <div class="table-responsive">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>Terminal ID</th>
            <th>Token Type</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Expires At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($terminalTokens as $token)
          <tr>
            <td>{{ $token->terminal_id ?? 'Unknown' }}</td>
            <td>{{ $token->token_type ?? 'Bearer' }}</td>
            <td>
              @if(($token->is_revoked ?? false))
              <span class="badge bg-danger">Revoked</span>
              @elseif(($token->expires_at ?? now()) < now()) <span class="badge bg-warning">Expired</span>
                @else
                <span class="badge bg-success">Active</span>
                @endif
            </td>
            <td>{{ $token->created_at }}</td>
            <td>{{ $token->expires_at ?? 'Never' }}</td>
            <td>
              <form method="POST" action="{{ route('regenerate-token', ['terminalId' => $token->terminal_id ?? 0]) }}"
                class="token-form">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">Regenerate JWT</button>
              </form>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @else
    <p>No terminal tokens found.</p>
    @endif
  </div>
</div>

<!-- Token Modal -->
<div class="modal fade" id="tokenModal" tabindex="-1" aria-labelledby="tokenModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tokenModalLabel">JWT Token Regenerated</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i> Store this token securely! It will not be displayed again.
        </div>
        <div class="mb-3">
          <label class="form-label">Terminal ID:</label>
          <p id="modal-terminal-id" class="form-control-plaintext">-</p>
        </div>
        <div class="mb-3">
          <label class="form-label">Token Type:</label>
          <p id="modal-token-type" class="form-control-plaintext">Bearer</p>
        </div>
        <div class="mb-3">
          <label class="form-label">JWT Token:</label>
          <div class="input-group">
            <textarea id="modal-access-token" class="form-control font-monospace" rows="4" readonly></textarea>
            <button class="btn btn-outline-secondary" type="button" id="copy-token-btn">Copy</button>
          </div>
          <small class="text-muted">Use this token in the Authorization header for API requests.</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Header Format:</label>
          <pre
            class="bg-light p-2 border rounded"><code>Authorization: Bearer <span id="token-placeholder">JWT_TOKEN</span></code></pre>
        </div>
        <div class="mb-3">
          <label class="form-label">Expires At:</label>
          <p id="modal-expires-at" class="form-control-plaintext">-</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
  const forms = document.querySelectorAll('.token-form');

  forms.forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      const formData = new FormData(form);
      const url = form.getAttribute('action');

      fetch(url, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            // Show regenerated token in the modal
            document.getElementById('modal-terminal-id').textContent = data.data.terminal_id || '-';
            document.getElementById('modal-token-type').textContent = data.data.token_type || 'Bearer';
            document.getElementById('modal-access-token').value = data.data.access_token || '-';
            document.getElementById('token-placeholder').textContent = data.data.access_token ||
              'JWT_TOKEN';
            document.getElementById('modal-expires-at').textContent = data.data.expires_at || '-';

            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('tokenModal'));
            modal.show();
          } else {
            alert(data.message || 'Error regenerating token');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('Error regenerating token. See console for details.');
        });
    });
  });

  // Add copy button functionality
  document.getElementById('copy-token-btn').addEventListener('click', function() {
    const tokenField = document.getElementById('modal-access-token');
    tokenField.select();
    document.execCommand('copy');
    this.textContent = 'Copied!';
    setTimeout(() => {
      this.textContent = 'Copy';
    }, 2000);
  });
});
</script>
@endsection