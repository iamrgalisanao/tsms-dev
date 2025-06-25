{{-- filepath: resources/views/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>TSMS Login</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
    }
    body {
      min-height: 100vh;
      background: #fff;
    }
    .split-container {
      display: flex;
      min-height: 100vh;
    }
    .left-panel {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #fff;
    }
    .right-panel {
      flex: 1;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      min-height: 100vh;
    }
    .right-panel-content {
      position: relative;
      z-index: 2;
      text-align: center;
      width: 100%;
      color: #fff;
    }
    .right-panel-bg {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      background: url('{{ asset('images/bg.png') }}') center center/cover no-repeat;
      z-index: 1;
    }
    .right-panel-overlay {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      /* background: rgba(122, 19, 22, 0.95); Maroon with opacity */
      background: rgba(29, 67, 155, .90) !important; /* Blue with opacity */
      z-index: 1;
    }
    .login-card {
      width: 100%;
      max-width: 400px;
      border-radius: 12px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.08);
      background: #fff;
      padding: 2.5rem 2rem;
    }
    .login-logo {
      display: block;
      margin: 0 auto 2rem auto;
      max-width: 120px;
    }
    .login-title {
      font-weight: 700;
      font-size: 2rem;
      margin-bottom: 0.5rem;
      color: #222;
      text-align: left;
    }
    .login-subtitle {
      color: #888;
      margin-bottom: 2rem;
      text-align: left;
    }
    .form-label {
      font-weight: 500;
    }
    .btn-primary {
      background-color: #e53935;
      border-color: #e53935;
      width: 100%;
      font-weight: 600;
      font-size: 1.1rem;
      border-radius: 6px;
      margin-top: 0.5rem;
    }
    .btn-primary:hover {
      background-color: #b71c1c;
      border-color: #b71c1c;
    }
    .forgot-link {
      color: #e53935;
      text-decoration: none;
      font-size: 0.95rem;
      float: right;
    }
    .forgot-link:hover {
      text-decoration: underline;
    }
    .keep-logged {
      font-size: 0.95rem;
    }
    .right-panel-logo {
      max-width: 280px;
      margin-bottom: 2rem;
      background: rgba(255,255,255,1);
      border-radius: 8px;
      padding: 0.5rem 1rem;
      display: inline-block;
    }
    .right-panel-title {

      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      letter-spacing: 1px;
      color: #fff;
      text-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .right-panel-subtitle {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.1rem;
      color: #fff;
      opacity: 0.9;
      text-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    @media (max-width: 900px) {
      .split-container {
        flex-direction: column;
      }
      .right-panel, .left-panel {
        flex: unset;
        min-height: 40vh;
      }
    }
  </style>
</head>

<body>
  <div class="split-container">
    <!-- Left: Login Form -->
    <div class="left-panel">
      <div class="login-card">
        {{-- <img src="{{ asset('images/pitx_logo_2.png') }}" alt="PITX Logo" class="login-logo"> --}}
        <div class="login-title">Sign In</div>
        <div class="login-subtitle">Enter your username and password to sign in!</div>
        @if(session('error'))
        <div class="alert alert-danger mb-3">
          {{ session('error') }}
        </div>
        @endif
        <form method="POST" action="{{ route('login') }}">
          @csrf
          <div class="mb-3">
            <label for="email" class="form-label">Username</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
              value="{{ old('email') }}" required autofocus placeholder="Enter your username">
            @error('email')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror" id="password"
              name="password" required placeholder="Enter your password">
            @error('password')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>
          {{-- <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check keep-logged">
              <input type="checkbox" class="form-check-input" id="remember_me" name="remember">
              <label class="form-check-label" for="remember_me">Keep me logged in</label>
            </div>
            <a href="#" class="forgot-link">Forgot password?</a>
          </div> --}}
          <div class="d-flex justify-content-between align-items-center">
            <p>Demo Account:</p>
            <p class="text-muted">admin@example.com<strong>
              </strong> | password123</p> 
            
          </div>
          <button type="submit" class="btn btn-primary">Sign in</button>
        </form>
      </div>
    </div>
    <!-- Right: Branding with background image and overlay -->
    <div class="right-panel">
      <div class="right-panel-bg"></div>
      <div class="right-panel-overlay"></div>
      <div class="right-panel-content">
        <img src="{{ asset('images/pitx_logo_2.png') }}" alt="PITX Logo" class="right-panel-logo">
        {{-- <div class="right-panel-title">PITX</div> --}}
        <div class="right-panel-subtitle">Tenant Sales Management System</div>
      </div>
    </div>
  </div>
  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>