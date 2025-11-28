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
