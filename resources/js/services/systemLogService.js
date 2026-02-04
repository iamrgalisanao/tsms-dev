import axios from 'axios';

const api = axios.create({
    baseURL: '/system-logs',
    headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    }
});

export const systemLogService = {
    /**
     * Fetch paginated and filtered logs
     * @param {Object} params - { type, severity, date_from, date_to, terminal, system_page, audit_page, webhook_page, submission_page, search }
     */
    getLogs: async (params = {}) => {
        try {
            const response = await api.get('', { params });
            return response.data;
        } catch (error) {
            console.error('Error fetching system logs:', error);
            throw error;
        }
    },

    /**
     * Prune logs based on criteria (Admin only)
     * @param {Object} data - { before, days, type, dry_run, force }
     */
    pruneLogs: async (data) => {
        try {
            const response = await axios.post('/system-logs/prune', data, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            return response.data;
        } catch (error) {
            console.error('Error pruning logs:', error);
            throw error;
        }
    },

    /**
     * Bulk action on logs (Mark as archived/restore/purge)
     * @param {string} action - 'soft-delete', 'restore', 'purge'
     * @param {Array} ids - List of log item IDs
     */
    bulkAction: async (action, ids) => {
        const endpoints = {
            'soft-delete': '/system-logs/bulk-soft-delete',
            'restore': '/system-logs/bulk-restore',
            'purge': '/system-logs/bulk-purge'
        };

        try {
            const response = await axios.post(endpoints[action], { ids }, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            return response.data;
        } catch (error) {
            console.error(`Error performing bulk action ${action}:`, error);
            throw error;
        }
    }
};
