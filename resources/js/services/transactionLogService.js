import axios from 'axios';

const LOGS_BASE = '/transactions/logs';

export const transactionLogService = {
    /**
     * Get paginated list of transactions (detailed view)
     * @param {Object} filters - Filter parameters
     * @param {number} page - Page number
     * @param {number} perPage - Items per page
     */
    getTransactions: async (filters = {}, page = 1, perPage = 15) => {
        const params = {
            ...filters,
            page,
            per_page: perPage
        };

        const response = await axios.get(LOGS_BASE, { params });
        return response.data;
    },

    /**
     * Get summary view (grouped aggregations)
     * @param {Object} filters - Filter parameters
     * @param {number} page - Page number
     * @param {number} perPage - Items per page
     */
    getSummary: async (filters = {}, page = 1, perPage = 15) => {
        const params = {
            ...filters,
            page,
            per_page: perPage
        };

        const response = await axios.get(`${LOGS_BASE}/summary`, { params });
        return response.data;
    },

    /**
     * Get detailed information for a single transaction
     * @param {number} id - Transaction ID
     */
    getTransactionDetails: async (id) => {
        const response = await axios.get(`${LOGS_BASE}/${id}`);
        return response.data;
    },

    /**
     * Get count of transactions with WITH_ISSUES status
     * @param {Object} filters - Filter parameters
     */
    getIssuesCount: async (filters = {}) => {
        const response = await axios.get(`${LOGS_BASE}/issues-count`, { params: filters });
        return response.data;
    },

    /**
     * Export transactions to Excel
     * @param {Object} filters - Filter parameters
     */
    exportToExcel: async (filters = {}) => {
        const response = await axios.get(`${LOGS_BASE}/export`, {
            params: filters,
            responseType: 'blob'
        });

        const dateBasis = filters.date_basis || 'completed';

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `transaction-logs-${dateBasis}-date-${new Date().toISOString().split('T')[0]}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    },

    /**
     * Get list of terminals for filter dropdown
     */
    getTerminals: async () => {
        const response = await axios.get(`${LOGS_BASE}/terminals`);
        return response.data;
    },

    /**
     * Get list of tenants for filter dropdown
     */
    getTenants: async () => {
        const response = await axios.get(`${LOGS_BASE}/tenants`);
        return response.data;
    },

    /**
     * Trigger manual reconciliation
     */
    reconcile: async () => {
        const response = await axios.post(`${LOGS_BASE}/reconcile`);
        return response.data;
    }
};
