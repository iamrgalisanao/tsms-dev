import axios from 'axios';

const API_BASE = window.config?.api_base || '/api';

const userService = {
    getUsers: async (filters = {}, page = 1, perPage = 10) => {
        const response = await axios.get(`${API_BASE}/users`, {
            params: {
                ...filters,
                page,
                per_page: perPage
            }
        });
        return response.data;
    },

    getRoles: async () => {
        const response = await axios.get(`${API_BASE}/users/roles`);
        return response.data;
    },

    createUser: async (userData) => {
        const response = await axios.post(`${API_BASE}/users`, userData);
        return response.data;
    },

    updateUser: async (id, userData) => {
        const response = await axios.put(`${API_BASE}/users/${id}`, userData);
        return response.data;
    },

    deleteUser: async (id) => {
        const response = await axios.delete(`${API_BASE}/users/${id}`);
        return response.data;
    }
};

export default userService;
