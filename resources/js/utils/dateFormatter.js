// Use dayjs for timezone conversion
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import timezone from 'dayjs/plugin/timezone';

dayjs.extend(utc);
dayjs.extend(timezone);

const ASIA_MANILA = 'Asia/Manila';

/**
 * Format a timestamp string as "Mon DD, YYYY, HH:MM:SS AM/PM" in Asia/Manila time.
 * @param {string} dateString - Raw timestamp from the API (UTC or ISO8601)
 * @param {boolean} includeSeconds - Whether to include seconds
 * @returns {string}
 */
export const formatDate = (dateString, includeSeconds = true) => {
    if (!dateString) return 'N/A';
    const d = dayjs.utc(dateString).tz(ASIA_MANILA);
    if (!d.isValid()) return '-';
    const formatStr = includeSeconds
        ? 'MMM DD, YYYY, hh:mm:ss A'
        : 'MMM DD, YYYY, hh:mm A';
    return d.format(formatStr);
};

/**
 * Format time portion only as "HH:MM:SS AM/PM" in Asia/Manila time.
 * @param {string} dateString - Raw timestamp from the API
 * @returns {string}
 */
export const formatTimeOnly = (dateString) => {
    if (!dateString) return 'N/A';
    const d = dayjs.utc(dateString).tz(ASIA_MANILA);
    if (!d.isValid()) return '-';
    return d.format('hh:mm:ss A');
};
