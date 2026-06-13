import axios from 'axios';

const API_BASE = window.config?.api_base || '/api';

export const incidentService = {
    /**
     * Fetch paginated incidents with optional filters.
     * @param {Object} params - { from, to, tenant_id, terminal_id, state, category, page, per_page }
     */
    getIncidents: async (params = {}) => {
        const response = await axios.get(`${API_BASE}/v1/incidents`, { params });
        return response.data;
    },

    /**
     * Fetch a single incident by id.
     * @param {number|string} id
     */
    getIncident: async (id) => {
        const response = await axios.get(`${API_BASE}/v1/incidents/${id}`);
        return response.data;
    }
};
