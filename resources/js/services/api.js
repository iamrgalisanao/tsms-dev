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
    exportTenantsCSV: async () => {
        const response = await axios.get('/api/tenants/export', {
            responseType: 'blob'
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `tenants-export-${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
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
    },
    // Observability
    getIntakeMetrics: async () => {
        const response = await axios.get('/api/v1/observability/intake');
        return response.data;
    },
    getIntakeHistory: async (metric = 'intake.processing_lag') => {
        const response = await axios.get('/api/v1/observability/intake/history', { params: { metric } });
        return response.data;
    },
    getTenantIntakeStats: async () => {
        const response = await axios.get('/api/v1/observability/intake/tenants');
        return response.data;
    },
    getIntakeRecent: async () => {
        const response = await axios.get('/api/v1/observability/intake/recent');
        return response.data;
    },
    getDuplicateReceiptReport: async (filters = {}) => {
        const params = Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined)
        );
        const response = await axios.get('/api/v1/observability/intake/duplicate-receipts', { params });
        return response.data;
    },
    getTenantIngestionAudit: async (filters = {}) => {
        const params = Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined)
        );
        const response = await axios.get('/api/v1/observability/intake/tenant-audit', { params });
        return response.data;
    },
    getProviderTenantActivity: async (thresholdMinutes = null) => {
        const params = thresholdMinutes ? { threshold_minutes: thresholdMinutes } : {};
        const response = await axios.get('/api/v1/monitoring/tenants/activity', { params });
        return response.data;
    },
    getProviderTerminalActivity: async (thresholdMinutes = null) => {
        const params = thresholdMinutes ? { threshold_minutes: thresholdMinutes } : {};
        const response = await axios.get('/api/v1/monitoring/terminals/activity', { params });
        return response.data;
    },
    getProviderDailyHeartbeatReport: async (date = null, thresholdMinutes = null) => {
        const params = {
            ...(date ? { date } : {}),
            ...(thresholdMinutes ? { threshold_minutes: thresholdMinutes } : {})
        };
        const response = await axios.get('/api/monitoring/activity/daily-report', { params });
        return response.data;
    },
    updateTenantMonitoringConfig: async (tenantId, data) => {
        const response = await axios.put(`/api/monitoring/tenants/${tenantId}/config`, data);
        return response.data;
    },
    updateTerminalMonitoringConfig: async (terminalId, data) => {
        const response = await axios.put(`/api/monitoring/terminals/${terminalId}/config`, data);
        return response.data;
    }
};

export default api;
