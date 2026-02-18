{{-- filepath: resources/views/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Sign In — TSMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap"
    rel="stylesheet">
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html,
    body {
      height: 100%;
      font-family: 'Inter', sans-serif;
    }

    .split {
      display: flex;
      min-height: 100vh;
    }

    /* ══════════════════════════════
       LEFT PANEL — Dark Navy Branding
    ══════════════════════════════ */
    .left {
      flex: 0 0 42%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background-color: #0B1628;
      padding: 2.5rem;
      position: relative;
      overflow: hidden;
    }

    /* Grid line overlay */
    .left::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
      background-size: 52px 52px;
      pointer-events: none;
    }

    .left-top {
      position: relative;
      z-index: 1;
    }

    .left-top .pitx-wordmark {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.18em;
      color: rgba(255, 255, 255, 0.35);
      text-transform: uppercase;
    }

    .left-center {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      flex: 1;
      justify-content: center;
    }

    .shield-box {
      width: 80px;
      height: 80px;
      background: #1A2B45;
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.75rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    }

    .shield-box svg {
      width: 40px;
      height: 40px;
      fill: #fff;
    }

    .tsms-wordmark {
      font-size: 3.5rem;
      font-weight: 900;
      color: #fff;
      letter-spacing: 0.04em;
      line-height: 1;
      margin-bottom: 0.6rem;
    }

    .red-rule {
      width: 48px;
      height: 4px;
      background: #E53935;
      border-radius: 2px;
      margin: 0 auto 1.5rem auto;
    }

    .system-name {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.2em;
      color: rgba(255, 255, 255, 0.7);
      text-transform: uppercase;
      margin-bottom: 0.35rem;
    }

    .portal-label {
      font-size: 0.65rem;
      font-weight: 500;
      letter-spacing: 0.25em;
      color: rgba(255, 255, 255, 0.35);
      text-transform: uppercase;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .portal-label::before,
    .portal-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: rgba(255, 255, 255, 0.15);
      max-width: 40px;
    }

    .left-bottom {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .node-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #4CAF50;
      box-shadow: 0 0 8px rgba(76, 175, 80, 0.6);
      flex-shrink: 0;
    }

    .node-label {
      font-size: 0.65rem;
      font-weight: 600;
      letter-spacing: 0.15em;
      color: rgba(255, 255, 255, 0.35);
      text-transform: uppercase;
    }

    /* ══════════════════════════════
       RIGHT PANEL — White Form
    ══════════════════════════════ */
    .right {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background: #fff;
      padding: 3.5rem 4rem;
    }

    .right-inner {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      max-width: 420px;
    }

    .form-heading {
      font-size: 2rem;
      font-weight: 800;
      color: #0B1628;
      line-height: 1.2;
      margin-bottom: 0.5rem;
      letter-spacing: -0.02em;
    }

    .form-subheading {
      font-size: 0.9rem;
      color: #6B7280;
      margin-bottom: 2.25rem;
      font-weight: 400;
    }

    /* Error alert */
    .alert-error {
      background: #FEF2F2;
      border: 1px solid #FECACA;
      color: #DC2626;
      border-radius: 8px;
      padding: 0.75rem 1rem;
      font-size: 0.875rem;
      margin-bottom: 1.25rem;
      font-weight: 500;
    }

    .form-group {
      margin-bottom: 1.25rem;
    }

    .form-label {
      display: block;
      font-size: 0.7rem;
      font-weight: 700;
      color: #9CA3AF;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      margin-bottom: 0.5rem;
    }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-icon {
      position: absolute;
      left: 0.9rem;
      color: #9CA3AF;
      display: flex;
      align-items: center;
    }

    .input-icon svg {
      width: 16px;
      height: 16px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
    }

    .form-input {
      width: 100%;
      background: #F9FAFB;
      border: 1.5px solid #E5E7EB;
      border-radius: 8px;
      padding: 0.75rem 1rem 0.75rem 2.5rem;
      font-size: 0.9rem;
      font-family: 'Inter', sans-serif;
      color: #111827;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-input::placeholder {
      color: #D1D5DB;
    }

    .form-input:focus {
      border-color: #6B7280;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
    }

    .form-input.is-invalid {
      border-color: #EF4444;
    }

    .invalid-feedback {
      color: #DC2626;
      font-size: 0.78rem;
      margin-top: 0.35rem;
      font-weight: 500;
    }

    .password-toggle {
      position: absolute;
      right: 0.9rem;
      background: none;
      border: none;
      cursor: pointer;
      color: #9CA3AF;
      padding: 0;
      display: flex;
      align-items: center;
      transition: color 0.2s;
    }

    .password-toggle:hover {
      color: #374151;
    }

    .password-toggle svg {
      width: 16px;
      height: 16px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
    }

    .form-options {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    .remember-label {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
      color: #374151;
      cursor: pointer;
      font-weight: 500;
    }

    .remember-label input[type="checkbox"] {
      width: 15px;
      height: 15px;
      border: 1.5px solid #D1D5DB;
      border-radius: 3px;
      cursor: pointer;
      accent-color: #E53935;
    }

    .recovery-link {
      font-size: 0.85rem;
      font-weight: 600;
      color: #E53935;
      text-decoration: none;
      transition: opacity 0.2s;
    }

    .recovery-link:hover {
      opacity: 0.75;
    }

    .btn-authenticate {
      width: 100%;
      padding: 0.9rem 1.5rem;
      background: #E53935;
      border: none;
      border-radius: 8px;
      color: #fff;
      font-size: 0.8rem;
      font-weight: 700;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      box-shadow: 0 4px 20px rgba(229, 57, 53, 0.3);
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    }

    .btn-authenticate:hover {
      background: #C62828;
      transform: translateY(-1px);
      box-shadow: 0 8px 25px rgba(229, 57, 53, 0.4);
    }

    .btn-authenticate:active {
      transform: translateY(0);
    }

    .btn-authenticate:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    .btn-authenticate svg {
      width: 17px;
      height: 17px;
      stroke: #fff;
      fill: none;
      stroke-width: 2.5;
    }

    /* Security badges */
    .security-section {
      margin-top: 3rem;
      text-align: center;
    }

    .security-label {
      font-size: 0.65rem;
      font-weight: 600;
      letter-spacing: 0.18em;
      color: #D1D5DB;
      text-transform: uppercase;
      margin-bottom: 0.75rem;
    }

    .badges {
      display: flex;
      justify-content: center;
      gap: 2rem;
    }

    .badge {
      display: flex;
      align-items: center;
      gap: 0.4rem;
    }

    .badge svg {
      width: 14px;
      height: 14px;
      stroke: #9CA3AF;
      fill: none;
      stroke-width: 2;
    }

    .badge-text strong {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      color: #374151;
      letter-spacing: 0.04em;
    }

    .badge-text span {
      font-size: 0.65rem;
      color: #9CA3AF;
    }

    /* Right footer */
    .right-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 2rem;
      border-top: 1px solid #F3F4F6;
      margin-top: 1rem;
    }

    .right-footer-left {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      color: #D1D5DB;
      text-transform: uppercase;
    }

    .right-footer-right {
      font-size: 0.7rem;
      color: #D1D5DB;
      font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 900px) {
      .split {
        flex-direction: column;
      }

      .left {
        flex: 0 0 auto;
        min-height: 45vh;
        padding: 2rem;
      }

      .right {
        padding: 2.5rem 1.5rem;
      }

      .right-inner {
        max-width: 100%;
      }
    }
  </style>
</head>

<body>
  <div class="split">

    {{-- ══ LEFT: BRANDING ══ --}}
    <div class="left">
      <div class="left-top">
        <span class="pitx-wordmark">PITX</span>
      </div>

      <div class="left-center">
        <div class="shield-box">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
              d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l6 2.67V11c0 3.88-2.62 7.5-6 8.93C8.62 18.5 6 14.88 6 11V7.67L12 5z" />
          </svg>
        </div>

        <div class="tsms-wordmark">TSMS</div>
        <div class="red-rule"></div>

        <div class="system-name">Transaction Management System</div>
        <div class="portal-label">Administration Portal</div>
      </div>

      <div class="left-bottom">
        <div class="node-dot"></div>
        <span class="node-label">NODE: MNL-CENTRAL-01 // SECURE</span>
      </div>
    </div>

    {{-- ══ RIGHT: FORM ══ --}}
    <div class="right">
      <div class="right-inner">
        <h1 class="form-heading">System Access<br>Command</h1>
        <p class="form-subheading">Enter your administrative credentials to continue.</p>

        {{-- Errors --}}
        @if(session('error'))
          <div class="alert-error">{{ session('error') }}</div>
        @endif

        @if($errors->any())
          <div class="alert-error">
            @foreach($errors->all() as $error)
              <div>{{ $error }}</div>
            @endforeach
          </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
          @csrf

          {{-- Email --}}
          <div class="form-group">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-wrapper">
              <span class="input-icon">
                <svg viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="4" />
                  <path d="M16 8v5a3 3 0 006 0v-1a10 10 0 10-3.92 7.94" />
                </svg>
              </span>
              <input type="email" id="email" name="email" class="form-input @error('email') is-invalid @enderror"
                value="{{ old('email') }}" placeholder="name@pitx.com.ph" required autofocus>
            </div>
            @error('email')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
          </div>

          {{-- Password --}}
          <div class="form-group">
            <label for="password" class="form-label">Security Password</label>
            <div class="input-wrapper">
              <span class="input-icon">
                <svg viewBox="0 0 24 24">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                  <path d="M7 11V7a5 5 0 0110 0v4" />
                </svg>
              </span>
              <input type="password" id="password" name="password"
                class="form-input @error('password') is-invalid @enderror" placeholder="••••••••••••" required
                style="padding-right: 2.75rem;">
              <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Toggle password">
                <svg id="eye-icon" viewBox="0 0 24 24">
                  <path
                    d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" />
                  <line x1="1" y1="1" x2="23" y2="23" />
                </svg>
              </button>
            </div>
            @error('password')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
          </div>

          {{-- Options row --}}
          <div class="form-options">
            <label class="remember-label">
              <input type="checkbox" name="remember" id="remember_me" {{ old('remember') ? 'checked' : '' }}>
              Remember session
            </label>
            <a href="#" class="recovery-link">Recovery access?</a>
          </div>

          {{-- Submit --}}
          <button type="submit" class="btn-authenticate" id="auth-btn">
            <svg viewBox="0 0 24 24">
              <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4" />
              <polyline points="10 17 15 12 10 7" />
              <line x1="15" y1="12" x2="3" y2="12" />
            </svg>
            Authenticate &amp; Access
          </button>
        </form>

        {{-- Security badges --}}
        <div class="security-section">
          <div class="security-label">Secured by PITX Enterprise</div>
          <div class="badges">
            <div class="badge">
              <svg viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              </svg>
              <div class="badge-text">
                <strong>SSL SECURE</strong>
                <span>256-bit encryption</span>
              </div>
            </div>
            <div class="badge">
              <svg viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
              </svg>
              <div class="badge-text">
                <strong>256-BIT AES</strong>
                <span>Data protection</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="right-footer">
        <span class="right-footer-left">V2.0.1-PRO</span>
        <span class="right-footer-right">&copy; {{ date('Y') }} PITX</span>
      </div>
    </div>

  </div>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      const icon = document.getElementById('eye-icon');
      if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
      } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
      }
    }

    document.querySelector('form').add EventListener('submit', function() {
      const btn = document.getElementById('auth-btn');
      btn.disabled = true;
      btn.textContent = 'Authenticating...';
    });
  </script>
</body>

</html>