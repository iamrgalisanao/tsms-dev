import axios from 'axios';

const base = '/api/admin/corrections';

export const adminCorrectionService = {
    listTenants: async (params = {}) => {
        const response = await axios.get(`${base}/tenants`, { params });
        return response.data;
    },

    backup: async (payload) => {
        const response = await axios.post(`${base}/backup`, payload);
        return response.data;
    },

    apply: async (payload) => {
        const response = await axios.post(`${base}/apply`, payload);
        return response.data;
    }
};
