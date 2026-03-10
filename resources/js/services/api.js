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
    },
    // Tenant Management
    getTenants: async () => {
        const response = await axios.get('/api/tenants');
        return response.data;
    },
    createTenant: async (data) => {
        const response = await axios.post('/api/tenants', data);
        return response.data;
    },
    updateTenant: async (id, data) => {
        const response = await axios.put(`/api/tenants/${id}`, data);
        return response.data;
    },
    deleteTenant: async (id) => {
        const response = await axios.delete(`/api/tenants/${id}`);
        return response.data;
    },
    // Tenant User Management
    getTenantUsers: async (tenantId) => {
        const response = await axios.get(`/api/tenants/${tenantId}/users`);
        return response.data;
    },
    createTenantUser: async (tenantId, data) => {
        const response = await axios.post(`/api/tenants/${tenantId}/users`, data);
        return response.data;
    },
    deleteTenantUser: async (tenantId, userId) => {
        const response = await axios.delete(`/api/tenants/${tenantId}/users/${userId}`);
        return response.data;
    }
};

export default api;
