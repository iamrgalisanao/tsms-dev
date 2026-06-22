import axios from 'axios';

const API_BASE = window.config?.api_base || '/api';

export const terminalTokenService = {
    /**
     * Update expiry date for a terminal
     * @param {string|number} terminalId
     * @param {string} newDate
     */
    updateExpiry: async (terminalId, newDate) => {
        // Ensure CSRF cookie is set for session-auth requests
        await axios.get('/sanctum/csrf-cookie');
        return axios.put(`/api/terminals/${terminalId}/expiry`, { expires_at: newDate });
    },
    /**
     * Get paginated list of terminals with their tokens
     * @param {Object} filters - Filter parameters
     * @param {number} page - Page number
     * @param {number} perPage - Items per page
     */
    getTerminalsWithTokens: async (filters = {}, page = 1, perPage = 15) => {
        const params = {
            ...filters,
            page,
            per_page: perPage
        };

        const response = await axios.get(`${API_BASE}/terminals/tokens`, { params });
        return response.data;
    },

    /**
     * Export terminal tokens to CSV
     * @param {Object} filters - Filter parameters
     */
    exportCSV: async (filters = {}) => {
        console.log('terminalTokenService: exportCSV called with filters:', filters);
        const response = await axios.get(`${API_BASE}/terminals/tokens/export`, {
            params: filters,
            responseType: 'blob'
        });
        console.log('terminalTokenService: exportCSV response received', response);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `terminal-tokens-${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        console.log('terminalTokenService: exportCSV download link clicked and cleaned up');
    },

    /**
     * Regenerate API token for a terminal
     * @param {string|number} terminalId 
     */
    regenerateToken: async (terminalId) => {
        const response = await axios.post(`${API_BASE}/terminals/tokens/${terminalId}/regenerate`);
        return response.data;
    },

    /**
     * Revoke API tokens for a terminal
     * @param {string|number} terminalId 
     */
    revokeTokens: async (terminalId) => {
        const response = await axios.post(`${API_BASE}/terminals/tokens/${terminalId}/revoke`);
        return response.data;
    },

    /**
     * Get list of terminals for filter dropdown
     */
    getTerminals: async () => {
        const response = await axios.get(`${API_BASE}/terminals`);
        return response.data;
    },

    /**
     * Register a new POS terminal
     */
    registerTerminal: async (payload) => {
        const response = await axios.post(`${API_BASE}/terminals`, payload);
        return response.data;
    },

    /**
     * Update admin-managed terminal metadata without rotating API tokens.
     */
    updateTerminal: async (terminalId, payload) => {
        const response = await axios.put(`${API_BASE}/terminals/${terminalId}`, payload);
        return response.data;
    },

    /**
     * Get list of tenants for filter dropdown
     */
    getTenants: async () => {
        const response = await axios.get(`${API_BASE}/tenants`);
        return response.data;
    }
};
