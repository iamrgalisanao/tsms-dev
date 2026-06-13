import axios from 'axios';

const API_BASE = window.config?.api_base || '/api';

export const dlqService = {
    /** DLQ health stats (count, oldest age, by-queue breakdown). */
    getStats: async () => {
        const res = await axios.get(`${API_BASE}/v1/admin/failed-jobs/stats`);
        return res.data;
    },

    /** Paginated list of failed jobs. */
    getFailedJobs: async (params = {}) => {
        const res = await axios.get(`${API_BASE}/v1/admin/failed-jobs`, { params });
        return res.data;
    },

    /** Single failed job detail by UUID. */
    getFailedJob: async (uuid) => {
        const res = await axios.get(`${API_BASE}/v1/admin/failed-jobs/${uuid}`);
        return res.data;
    },

    /** Retry a single failed job. */
    retry: async (uuid) => {
        const res = await axios.post(`${API_BASE}/v1/admin/failed-jobs/${uuid}/retry`);
        return res.data;
    },

    /** Retry all failed jobs. */
    retryAll: async () => {
        const res = await axios.post(`${API_BASE}/v1/admin/failed-jobs/retry-all`);
        return res.data;
    },

    /** Permanently delete a single failed job from DLQ. */
    flush: async (uuid) => {
        const res = await axios.delete(`${API_BASE}/v1/admin/failed-jobs/${uuid}`);
        return res.data;
    },
};
