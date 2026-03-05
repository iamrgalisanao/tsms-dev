import axios from 'axios';

const API_BASE = window.config?.api_base || '/api';

export const terminalTokenService = {
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
     * Get list of tenants for filter dropdown
     */
    getTenants: async () => {
        const response = await axios.get(`${API_BASE}/tenants`);
        return response.data;
    }
};
