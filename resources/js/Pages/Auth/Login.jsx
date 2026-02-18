import React, { useState } from 'react';
import { useAuth } from '../../Contexts/AuthContext';
import { useNavigate, useLocation } from 'react-router-dom';

/* ─────────────────────────────────────────────────────────────
   TSMS – Login Page  (Responsive)
   Desktop : dark navy left panel + white right form panel
   Mobile  : white form only, compact TSMS logo header on top
   Source  : Stitch "TSMS - Login Screen" + mobile Stitch design
───────────────────────────────────────────────────────────── */

const CSS = `
  /* ── Reset ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  /* ── Root ── */
  html, body, #app {
    margin: 0;
    padding: 0;
    background: #fff !important;
    min-height: 100vh;
  }
  .tsms-login-root {
    display: flex;
    min-height: 100vh;
    width: 100%;
    font-family: 'Inter', 'Segoe UI', sans-serif;
    background: #fff;
    -webkit-font-smoothing: antialiased;
  }

  /* ════════════════════════════════════════
     LEFT PANEL  (desktop only)
  ════════════════════════════════════════ */
  .tsms-left {
    display: none; /* hidden on mobile */
    flex: 0 0 50%;
    background: #0B192E;
    position: relative;
    align-items: center;
    justify-content: center;
    padding: 64px;
    overflow: hidden;
  }

  /* dot-grid */
  .tsms-left::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(to right, rgba(255,255,255,0.03) 1px, transparent 1px),
      linear-gradient(to bottom, rgba(255,255,255,0.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
  }

  /* radial glow */
  .tsms-left::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(circle at 20% 30%, rgba(230,57,70,0.05) 0%, transparent 50%),
      radial-gradient(circle at 80% 70%, rgba(255,255,255,0.02) 0%, transparent 50%);
    pointer-events: none;
  }

  .tsms-brand {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .tsms-shield-box {
    width: 112px;
    height: 112px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 32px;
    transition: transform 0.4s;
  }
  .tsms-shield-box:hover { transform: scale(1.05); }

  .tsms-shield-svg {
    width: 60px;
    height: 60px;
    fill: white;
    opacity: 0.9;
  }

  .tsms-wordmark {
    font-size: 72px;
    font-weight: 900;
    color: #fff;
    letter-spacing: -1px;
    line-height: 1;
    margin-bottom: 16px;
  }

  .tsms-red-bar {
    width: 64px;
    height: 6px;
    background: #E63946;
    border-radius: 3px;
    margin-bottom: 32px;
  }

  .tsms-system-title {
    color: rgba(255,255,255,0.9);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    line-height: 1.8;
  }

  .tsms-system-sub {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 8px;
  }
  .tsms-system-sub span {
    color: rgba(255,255,255,0.4);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 5px;
    text-transform: uppercase;
  }
  .tsms-system-sub .line {
    width: 32px;
    height: 1px;
    background: rgba(255,255,255,0.2);
  }

  .tsms-node {
    position: absolute;
    bottom: 48px;
    left: 48px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 1;
  }
  .tsms-node-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10B981;
    box-shadow: 0 0 8px #10B981;
    animation: tsms-pulse 2s infinite;
  }
  .tsms-node-text {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255,255,255,0.3);
    letter-spacing: 3px;
    text-transform: uppercase;
  }

  /* ════════════════════════════════════════
     RIGHT PANEL  (form)
  ════════════════════════════════════════ */
  .tsms-right {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px;
    background: #fff;
    position: relative;
    overflow-y: auto;
  }

  .tsms-form-inner {
    width: 100%;
    max-width: 440px;
  }

  /* ── Mobile logo header (hidden on desktop) ── */
  .tsms-mobile-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 40px;
  }
  .tsms-mobile-logo-box {
    width: 40px;
    height: 40px;
    background: #0B192E;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .tsms-mobile-logo-svg {
    width: 22px;
    height: 22px;
    fill: white;
  }
  .tsms-mobile-logo-text {
    font-size: 24px;
    font-weight: 900;
    color: #0B192E;
    letter-spacing: -0.5px;
  }

  /* ── Form header ── */
  .tsms-form-header {
    margin-bottom: 40px;
    text-align: center;
  }
  .tsms-form-heading {
    font-size: 32px;
    font-weight: 800;
    color: #0F172A;
    letter-spacing: -0.5px;
    line-height: 1.2;
    margin-bottom: 12px;
  }
  .tsms-form-sub {
    font-size: 16px;
    color: #64748B;
    font-weight: 400;
    line-height: 1.5;
  }

  /* ── Error box ── */
  .tsms-error {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    color: #DC2626;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* ── Field ── */
  .tsms-field { margin-bottom: 24px; }
  .tsms-field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #94A3B8;
    letter-spacing: 3px;
    text-transform: uppercase;
    margin-bottom: 8px;
    padding-left: 4px;
  }
  .tsms-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }
  .tsms-input-icon {
    position: absolute;
    left: 16px;
    color: #94A3B8;
    pointer-events: none;
    display: flex;
    align-items: center;
    transition: color 0.2s;
  }
  .tsms-input-icon svg { width: 20px; height: 20px; }
  .tsms-input-wrap:focus-within .tsms-input-icon { color: #E63946; }

  .tsms-input {
    width: 100%;
    padding: 16px 16px 16px 48px;
    background: #F8FAFC;
    border: 1.5px solid #E2E8F0;
    border-radius: 12px;
    font-size: 15px;
    color: #0F172A;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02), inset 0 1px 2px rgba(0,0,0,0.02);
    font-family: inherit;
  }
  .tsms-input::placeholder { color: #94A3B8; }
  .tsms-input:focus {
    border-color: #E63946;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(230,57,70,0.12);
  }
  .tsms-input-pr { padding-right: 48px; }

  .tsms-eye-btn {
    position: absolute;
    right: 16px;
    background: none;
    border: none;
    cursor: pointer;
    color: #94A3B8;
    display: flex;
    align-items: center;
    padding: 0;
    transition: color 0.2s;
  }
  .tsms-eye-btn:hover { color: #0B192E; }
  .tsms-eye-btn svg { width: 20px; height: 20px; }

  /* ── Remember row ── */
  .tsms-remember-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 4px;
    margin-bottom: 28px;
    margin-top: 4px;
  }
  .tsms-remember-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    user-select: none;
  }
  .tsms-remember-label:hover { color: #334155; }
  .tsms-checkbox {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1.5px solid #CBD5E1;
    accent-color: #E63946;
    cursor: pointer;
  }
  .tsms-recovery-link {
    font-size: 12px;
    font-weight: 700;
    color: #E63946;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
    font-family: inherit;
  }
  .tsms-recovery-link:hover { color: #C12E39; }

  /* ── Submit button ── */
  .tsms-submit {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #E63946 0%, #C12E39 100%);
    color: #fff;
    border: none;
    border-top: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    box-shadow: 0 8px 24px rgba(230,57,70,0.2);
    transition: box-shadow 0.2s, transform 0.1s;
    font-family: inherit;
  }
  .tsms-submit:hover {
    box-shadow: 0 12px 32px rgba(230,57,70,0.3);
  }
  .tsms-submit:active { transform: scale(0.99); }
  .tsms-submit:disabled {
    background: linear-gradient(135deg, #EF9A9A 0%, #EF9A9A 100%);
    cursor: not-allowed;
    box-shadow: none;
  }
  .tsms-submit svg { width: 20px; height: 20px; }

  /* ── Footer ── */
  .tsms-footer {
    margin-top: 48px;
    padding-top: 32px;
    border-top: 1px solid #F1F5F9;
  }
  .tsms-footer-secured {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 24px;
  }
  .tsms-footer-line { width: 24px; height: 1px; background: #E2E8F0; }
  .tsms-footer-secured-text {
    font-size: 10px;
    font-weight: 700;
    color: #94A3B8;
    letter-spacing: 4px;
    text-transform: uppercase;
  }
  .tsms-badge-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 40px;
  }
  .tsms-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: default;
  }
  .tsms-badge-icon { color: #CBD5E1; }
  .tsms-badge-icon svg { width: 20px; height: 20px; }
  .tsms-badge-label {
    font-size: 9px;
    font-weight: 900;
    color: #64748B;
    letter-spacing: 3px;
    text-transform: uppercase;
    line-height: 1;
    display: block;
  }
  .tsms-badge-sub {
    font-size: 8px;
    color: #CBD5E1;
    font-weight: 500;
    display: block;
    margin-top: 2px;
  }
  .tsms-badge-divider { width: 1px; height: 24px; background: #F1F5F9; }

  /* ── Version footer (desktop only) ── */
  .tsms-version {
    position: absolute;
    bottom: 32px;
    right: 48px;
    display: none;
    align-items: center;
    gap: 24px;
    color: #CBD5E1;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
  }
  .tsms-version-dot {
    width: 4px;
    height: 4px;
    background: #E2E8F0;
    border-radius: 50%;
  }

  /* ── Spinner ── */
  .tsms-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: tsms-spin 0.7s linear infinite;
    display: inline-block;
  }

  /* ════════════════════════════════════════
     RESPONSIVE BREAKPOINTS
  ════════════════════════════════════════ */

  /* Mobile default: form only, full width, white */
  .tsms-left { display: none; }
  .tsms-mobile-logo { display: flex; }
  .tsms-form-header { text-align: center; }
  .tsms-version { display: none !important; }

  .tsms-right {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 40px 24px;
    background: #fff;
    min-height: 100vh;
    overflow-y: auto;
  }

  .tsms-form-inner {
    width: 100%;
    max-width: 440px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    flex: 1;
  }

  /* Small phones (≤380px) */
  @media (max-width: 380px) {
    .tsms-right { padding: 32px 20px; }
    .tsms-form-heading { font-size: 24px; }
    .tsms-form-sub { font-size: 14px; }
    .tsms-mobile-logo { margin-bottom: 28px; }
    .tsms-form-header { margin-bottom: 28px; }
    .tsms-field { margin-bottom: 18px; }
    .tsms-input { padding: 13px 13px 13px 44px; font-size: 14px; }
    .tsms-submit { padding: 14px; font-size: 12px; }
    .tsms-footer { margin-top: 32px; padding-top: 24px; }
  }

  /* Medium phones (381–640px) */
  @media (min-width: 381px) and (max-width: 640px) {
    .tsms-right { padding: 40px 28px; }
    .tsms-form-heading { font-size: 28px; }
  }

  /* Tablets (641–1023px) */
  @media (min-width: 641px) and (max-width: 1023px) {
    .tsms-right { padding: 60px 48px; }
    .tsms-form-heading { font-size: 32px; }
    .tsms-form-inner { max-width: 480px; }
  }

  /* Desktop (lg+): show left panel, hide mobile logo, left-align header */
  @media (min-width: 1024px) {
    .tsms-left { display: flex; }
    .tsms-right {
      flex: 1;
      padding: 80px;
      justify-content: center;
      min-height: auto;
    }
    .tsms-form-inner {
      max-width: 440px;
      flex: unset;
    }
    .tsms-mobile-logo { display: none; }
    .tsms-form-header { text-align: left; }
    .tsms-version { display: flex; }
  }

  /* ── Animations ── */
  @keyframes tsms-spin { to { transform: rotate(360deg); } }
  @keyframes tsms-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`;

/* ── SVG Icons ── */
const ShieldIcon = ({ size = 60, className = '' }) => (
    <svg viewBox="0 0 24 24" width={size} height={size} className={className} xmlns="http://www.w3.org/2000/svg" fill="currentColor">
        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
        <path d="M10 17l-3-3 1.41-1.41L10 14.17l5.59-5.58L17 10l-7 7z" fill="#0B192E" />
    </svg>
);

const AtIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="4" /><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94" />
    </svg>
);

const LockIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
    </svg>
);

const EyeIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
        <circle cx="12" cy="12" r="3" />
    </svg>
);

const EyeOffIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
        <line x1="1" y1="1" x2="23" y2="23" />
    </svg>
);

const LoginArrowIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
        <polyline points="10 17 15 12 10 7" />
        <line x1="15" y1="12" x2="3" y2="12" />
    </svg>
);

const ShieldCheckIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <polyline points="9 12 11 14 15 10" />
    </svg>
);

const ShieldWarningIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <line x1="12" y1="8" x2="12" y2="12" />
        <line x1="12" y1="16" x2="12.01" y2="16" />
    </svg>
);

/* ── Main Component ── */
export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [rememberMe, setRememberMe] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const from = location.state?.from?.pathname || '/dashboard';

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!email || !password) {
            setError('Please enter your email address and password.');
            return;
        }
        setError('');
        setLoading(true);
        try {
            await login(email, password);
            navigate(from, { replace: true });
        } catch (err) {
            setError(
                err?.response?.data?.message ||
                err?.message ||
                'Authentication failed. Please check your credentials.'
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <style>{CSS}</style>

            <div className="tsms-login-root">

                {/* ══ LEFT PANEL (desktop only) ══ */}
                <section className="tsms-left">
                    <div className="tsms-brand">
                        {/* Shield icon */}
                        <div className="tsms-shield-box">
                            <ShieldIcon size={60} className="tsms-shield-svg" />
                        </div>

                        {/* Wordmark */}
                        <div className="tsms-wordmark">TSMS</div>
                        <div className="tsms-red-bar" />

                        {/* System title */}
                        <p className="tsms-system-title">Transaction Sales Management System</p>
                        <div className="tsms-system-sub">
                            <span className="line" />
                            <span>Administration Portal</span>
                            <span className="line" />
                        </div>
                    </div>

                    {/* NODE status */}
                    <div className="tsms-node">
                        <div className="tsms-node-dot" />
                        <span className="tsms-node-text">Node: MNL-CENTRAL-01 // Secure</span>
                    </div>
                </section>

                {/* ══ RIGHT PANEL (form) ══ */}
                <main className="tsms-right">
                    <div className="tsms-form-inner">

                        {/* Mobile-only compact logo */}
                        <div className="tsms-mobile-logo">
                            <div className="tsms-mobile-logo-box">
                                <ShieldIcon size={22} className="tsms-mobile-logo-svg" />
                            </div>
                            <span className="tsms-mobile-logo-text">TSMS</span>
                        </div>

                        {/* Heading */}
                        <header className="tsms-form-header">
                            <h1 className="tsms-form-heading">System Access Command</h1>
                            <p className="tsms-form-sub">Enter your administrative credentials to continue.</p>
                        </header>

                        {/* Error */}
                        {error && (
                            <div className="tsms-error">
                                <span>⚠</span>
                                <span>{error}</span>
                            </div>
                        )}

                        <form onSubmit={handleSubmit} autoComplete="off">
                            {/* Email */}
                            <div className="tsms-field">
                                <label className="tsms-field-label" htmlFor="email">Email Address</label>
                                <div className="tsms-input-wrap">
                                    <span className="tsms-input-icon"><AtIcon /></span>
                                    <input
                                        id="email"
                                        type="email"
                                        className="tsms-input"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        placeholder="name@pitx.com.ph"
                                        disabled={loading}
                                        autoComplete="username"
                                        required
                                    />
                                </div>
                            </div>

                            {/* Password */}
                            <div className="tsms-field">
                                <label className="tsms-field-label" htmlFor="password">Security Password</label>
                                <div className="tsms-input-wrap">
                                    <span className="tsms-input-icon"><LockIcon /></span>
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        className="tsms-input tsms-input-pr"
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••••••"
                                        disabled={loading}
                                        autoComplete="current-password"
                                        required
                                    />
                                    <button
                                        type="button"
                                        className="tsms-eye-btn"
                                        onClick={() => setShowPassword((v) => !v)}
                                        tabIndex={-1}
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                                    </button>
                                </div>
                            </div>


                            {/* Remember + Recovery — commented out, implement when requested */}
                            {/* <div className="tsms-remember-row">
                                <label className="tsms-remember-label">
                                    <input
                                        type="checkbox"
                                        className="tsms-checkbox"
                                        checked={rememberMe}
                                        onChange={(e) => setRememberMe(e.target.checked)}
                                        disabled={loading}
                                    />
                                    Remember session
                                </label>
                                <button
                                    type="button"
                                    className="tsms-recovery-link"
                                    onClick={() => navigate('/forgot-password')}
                                >
                                    Recovery access?
                                </button>
                            </div> */}



                            {/* Submit */}
                            <button
                                type="submit"
                                className="tsms-submit"
                                disabled={loading}
                            >
                                {loading ? (
                                    <>
                                        <span className="tsms-spinner" />
                                        Authenticating...
                                    </>
                                ) : (
                                    <>
                                        <span>Authenticate &amp; Access</span>
                                        <LoginArrowIcon />
                                    </>
                                )}
                            </button>
                        </form>

                        {/* Footer */}
                        <footer className="tsms-footer">
                            <div className="tsms-footer-secured">
                                <span className="tsms-footer-line" />
                                <span className="tsms-footer-secured-text">Secured by PITX </span>
                                <span className="tsms-footer-line" />
                            </div>
                            <div className="tsms-badge-row">
                                <div className="tsms-badge">
                                    <span className="tsms-badge-icon"><ShieldCheckIcon /></span>
                                    <div>
                                        <span className="tsms-badge-label">SSL Secure</span>
                                        <span className="tsms-badge-sub">Verified Certificate</span>
                                    </div>
                                </div>
                                <div className="tsms-badge-divider" />
                                <div className="tsms-badge">
                                    <span className="tsms-badge-icon"><ShieldWarningIcon /></span>
                                    <div>
                                        <span className="tsms-badge-label">256-Bit AES</span>
                                        <span className="tsms-badge-sub">Military Grade</span>
                                    </div>
                                </div>
                            </div>
                        </footer>
                    </div>

                    {/* Version tag (desktop only via CSS) */}
                    <div className="tsms-version">
                        <span>v2.0.1-PRO</span>
                        <div className="tsms-version-dot" />
                        <span>© 2024 PITX</span>
                    </div>
                </main>

            </div>
        </>
    );
}
