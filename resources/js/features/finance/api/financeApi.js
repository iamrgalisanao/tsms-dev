import axios from 'axios';

/**
 * Fetch finance KPI metrics for a given date range.
 * @param {string} startDate - ISO date string (YYYY-MM-DD)
 * @param {string} endDate   - ISO date string (YYYY-MM-DD)
 */
export const fetchFinanceMetrics = async ({ startDate, endDate }) => {
    const { data } = await axios.get('/api/dashboard/metrics', {
        params: { start_date: startDate, end_date: endDate },
    });
    return data;
};

/**
 * Fetch chart time-series data for the given number of days.
 * @param {string|number} days - Number of trailing days (e.g. '7' or '30')
 */
export const fetchFinanceCharts = async ({ days }) => {
    const { data } = await axios.get('/api/dashboard/charts', {
        params: { days },
    });
    return data;
};
