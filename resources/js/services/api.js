import axios from 'axios';

const api = {
    getMetrics: async () => {
        const response = await axios.get('/api/dashboard/metrics');
        return response.data;
    },
    getCharts: async () => {
        const response = await axios.get('/api/dashboard/charts');
        return response.data;
    },
    getTransactions: async (page = 1, filters = {}) => {
        const params = { page, ...filters };
        const response = await axios.get('/api/dashboard/transactions', { params });
        return response.data;
    },
    getAuditLogs: async (page = 1, filters = {}) => {
        const params = { page, ...filters };
        const response = await axios.get('/api/dashboard/audit-logs', { params });
        return response.data;
    },
    getSystemHealth: async () => {
        const response = await axios.get('/api/dashboard/system-health');
        return response.data;
    },
    getTerminalPerformance: async () => {
        const response = await axios.get('/api/dashboard/terminal-performance');
        return response.data;
    },
    forwardTransaction: async (id) => {
        const response = await axios.post(`/api/dashboard/forward-transaction/${id}`);
        return response.data;
    },
    getNotifications: async () => {
        const response = await axios.get('/api/dashboard/notifications');
        return response.data;
    },
    dismissNotification: async (id) => {
        const response = await axios.post('/api/dashboard/notifications/dismiss', { id });
        return response.data;
    }
};

export default api;
