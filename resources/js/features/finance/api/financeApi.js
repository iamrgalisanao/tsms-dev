import axios from 'axios';

const isTemporarilyDisabled = (error) => error?.response?.status === 404;

const emptyFinanceMetrics = {
    total_sales: { current: 0, trend: 0, sparkline: [] },
    total_net_sales: { current: 0, trend: 0 },
    total_transactions: { current: 0, trend: 0, sparkline: [] },
    voided_transactions: { current: 0, trend: 0 },
    void_rate: { current: 0, trend: 0 },
    active_terminals: { current: 0, total: 0 },
    active_tenants: { current: 0, total: 0 },
    reconciliation: { reconciled: 0, total: 0, pending: 0, failed: 0, trend: 0 },
    pending_uploads: { current: 0 },
    exceptions: { failed_reconciliations: 0, missing_uploads: 0, invalid_tax_records: 0, total_exceptions: 0 },
    compliance: { csmr_ready: false, bir_export_generated: false, tax_validation_passed: false },
    top_tenants: [],
    revenue_composition: { net_sales: 0, tax_exempt: 0, vat: 0, refunds: 0, discounts: 0 },
    sync_status: { last_sync: null }
};

const emptyFinanceCharts = {
    granularity: 'daily',
    labels: [],
    sales: [],
    net_sales: [],
    volume: [],
    previous_sales: [],
    reconciled: [],
    exceptions: [],
    terminal_counts: [],
    tenant_counts: [],
    top_tenants: []
};

/**
 * Fetch finance KPI metrics for a given date range.
 * @param {string} startDate - ISO date string (YYYY-MM-DD)
 * @param {string} endDate   - ISO date string (YYYY-MM-DD)
 */
export const fetchFinanceMetrics = async ({ startDate, endDate }) => {
    try {
        const { data } = await axios.get('/api/dashboard/metrics', {
            params: { start_date: startDate, end_date: endDate },
        });
        return data;
    } catch (error) {
        if (isTemporarilyDisabled(error)) return emptyFinanceMetrics;
        throw error;
    }
};

/**
 * Fetch chart time-series data for the given number of days.
 * @param {string|number} days - Number of trailing days (e.g. '7' or '30')
 */
export const fetchFinanceCharts = async ({ days }) => {
    try {
        const { data } = await axios.get('/api/dashboard/charts', {
            params: { days },
        });
        return data;
    } catch (error) {
        if (isTemporarilyDisabled(error)) return emptyFinanceCharts;
        throw error;
    }
};
