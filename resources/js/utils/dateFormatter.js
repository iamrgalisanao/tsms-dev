/**
 * Utility to format transaction dates consistently across the application.
 *
 * IMPORTANT: We deliberately avoid new Date() for timestamp display.
 * Using new Date() and toLocaleString() causes timezone conversion that
 * shifts the time by the browser/server offset (e.g. +08:00 becomes 8 hrs off).
 *
 * Instead, we parse the timestamp string directly so that the literal
 * wall-clock time from the payload is always shown as-is.
 *
 * Input examples:
 *   "2026-03-12T09:54:01Z"  →  "Mar 12, 2026, 09:54:01 AM"
 *   "2026-03-12T09:54:01"   →  "Mar 12, 2026, 09:54:01 AM"
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * Parse an ISO-like timestamp string into its literal date/time parts.
 * Strips any trailing Z or timezone offset before parsing.
 * @param {string} dateString
 * @returns {{ year, month, day, hour, minute, second } | null}
 */
const parseTimestampParts = (dateString) => {
    if (!dateString || typeof dateString !== 'string') return null;

    // Strip Z / timezone offset (e.g. +08:00) — we treat the number literally
    const clean = dateString.replace(/[Zz]$/, '').replace(/[+-]\d{2}:\d{2}$/, '');

    // Match "YYYY-MM-DDTHH:MM:SS" or "YYYY-MM-DD HH:MM:SS"
    const match = clean.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/);
    if (!match) return null;

    return {
        year:   parseInt(match[1], 10),
        month:  parseInt(match[2], 10),  // 1-12
        day:    parseInt(match[3], 10),
        hour:   parseInt(match[4], 10),  // 0-23
        minute: parseInt(match[5], 10),
        second: parseInt(match[6], 10),
    };
};

/**
 * Format a timestamp string as "Mon DD, YYYY, HH:MM:SS AM/PM".
 * @param {string} dateString - Raw timestamp from the API
 * @param {boolean} includeSeconds - Whether to include seconds
 * @returns {string}
 */
export const formatDate = (dateString, includeSeconds = true) => {
    if (!dateString) return 'N/A';

    const parts = parseTimestampParts(dateString);
    if (!parts) return '-';

    const { year, month, day, hour, minute, second } = parts;
    const ampm  = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    const mm     = String(minute).padStart(2, '0');
    const ss     = String(second).padStart(2, '0');
    const dd     = String(day).padStart(2, '0');
    const mon    = MONTHS[month - 1] || '???';
    const h12str = String(hour12).padStart(2, '0');

    if (includeSeconds) {
        return `${mon} ${dd}, ${year}, ${h12str}:${mm}:${ss} ${ampm}`;
    }
    return `${mon} ${dd}, ${year}, ${h12str}:${mm} ${ampm}`;
};

/**
 * Format time portion only as "HH:MM:SS AM/PM".
 * @param {string} dateString - Raw timestamp from the API
 * @returns {string}
 */
export const formatTimeOnly = (dateString) => {
    if (!dateString) return 'N/A';

    const parts = parseTimestampParts(dateString);
    if (!parts) return '-';

    const { hour, minute, second } = parts;
    const ampm  = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    const h12str = String(hour12).padStart(2, '0');
    const mm     = String(minute).padStart(2, '0');
    const ss     = String(second).padStart(2, '0');

    return `${h12str}:${mm}:${ss} ${ampm}`;
};
