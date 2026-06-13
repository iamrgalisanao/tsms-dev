import _ from 'lodash';
import axios from 'axios';

// Expose to legacy inline scripts that expect a global `_`
window._ = _;

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

// Attach axios to window and configure defaults
try {
    window.axios = axios;
    window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

    // Add CSRF token to axios requests
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
        window.axios.defaults.headers.common["X-CSRF-TOKEN"] = token.content;
    } else {
        console.error(
            "CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token"
        );
    }
} catch (e) {
    console.error("Error setting up axios:", e);
}

// Helper: read cookie by name
function _readCookie(name) {
    const match = document.cookie.match(new RegExp('(^|;)\\s*' + name + '=([^;]+)'));
    return match ? match.pop() : null;
}

// Ensure axios has a fallback CSRF header if meta is missing (use XSRF-TOKEN cookie)
function ensureAxiosCSRF() {
    try {
        const meta = document.head.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = meta.content;
            return;
        }

        // Try cookie fallback (Laravel sets XSRF-TOKEN cookie)
        const xsrf = _readCookie('XSRF-TOKEN');
        if (xsrf) {
            // cookie is URL encoded
            try {
                window.axios.defaults.headers.common['X-CSRF-TOKEN'] = decodeURIComponent(xsrf);
            } catch (e) {
                window.axios.defaults.headers.common['X-CSRF-TOKEN'] = xsrf;
            }
            return;
        }

        console.warn('CSRF meta tag not present and XSRF-TOKEN cookie not found; requests may be rejected.');
    } catch (e) {
        console.error('Error ensuring axios CSRF header:', e);
    }
}

// Run once now
ensureAxiosCSRF();

// Re-apply CSRF headers when navigating via back/forward (bfcache) so header isn't missing
window.addEventListener('pageshow', function(event) {
    // pageshow can be fired for bfcache restores; re-run token setup
    ensureAxiosCSRF();
});

// UX: detect when AJAX returns an HTML login page (session expired) and show a clear banner
function _createSessionBanner() {
    if (document.getElementById('session-exp-banner')) return document.getElementById('session-exp-banner');
    const b = document.createElement('div');
    b.id = 'session-exp-banner';
    b.style.position = 'fixed';
    b.style.left = '0';
    b.style.right = '0';
    b.style.top = '0';
    b.style.zIndex = '99999';
    b.style.background = '#fff3cd';
    b.style.color = '#856404';
    b.style.borderBottom = '1px solid #ffeeba';
    b.style.padding = '10px 16px';
    b.style.display = 'flex';
    b.style.alignItems = 'center';
    b.style.justifyContent = 'space-between';
    b.innerHTML = '<div><strong>Session expired or signed out.</strong> Some data could not be loaded. <a id="session-exp-banner-login" href="/">Sign in</a> to continue.</div>';
    const btn = document.createElement('button');
    btn.textContent = 'Dismiss';
    btn.style.marginLeft = '12px';
    btn.className = 'btn btn-sm btn-light';
    btn.onclick = function(){ b.remove(); };
    b.appendChild(btn);
    document.body.appendChild(b);
    return b;
}

function _looksLikeHtml(body) {
    if (!body || typeof body !== 'string') return false;
    const s = body.slice(0, 512).toLowerCase();
    return /<!doctype html|<html|<head|<body|<form|<title>/.test(s);
}

// Axios response interceptor to detect HTML responses and auth failures
if (window.axios && window.axios.interceptors) {
    window.axios.interceptors.response.use(function(response) {
        try {
            const ct = (response && response.headers && (response.headers['content-type'] || response.headers['Content-Type'])) || '';
            if (typeof response.data === 'string' && (ct.indexOf('text/html') !== -1 || _looksLikeHtml(response.data))) {
                console.warn('AJAX returned HTML (possible session/login page). Showing session-expired banner.');
                _createSessionBanner();
                return Promise.reject(new Error('HTML response received for XHR'));
            }
        } catch (e) {
            // swallow
        }
        return response;
    }, function(error) {
        try {
            const resp = error && error.response;
            if (resp) {
                // common auth/session statuses
                if ([401, 419, 403].indexOf(resp.status) !== -1) {
                    console.warn('AJAX error status', resp.status, '— showing session-expired banner.');
                    _createSessionBanner();
                } else if (resp.data && typeof resp.data === 'string' && _looksLikeHtml(resp.data)) {
                    _createSessionBanner();
                }
            }
        } catch (e) {}
        return Promise.reject(error);
    });
}
