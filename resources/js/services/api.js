/**
 * Core API service for handling all HTTP requests
 */
const BASE_URL = '/api/dashboard';

class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
        this.name = 'ApiError';
    }
}

async function handleResponse(response) {
    const data = await response.json();
    
    if (!response.ok) {
        throw new ApiError(
            data.message || 'An error occurred',
            response.status,
            data
        );
    }
    
    return data;
}

const defaultHeaders = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
};

export const api = {
    transactions: {
        list: async (params = {}) => {
            const queryString = new URLSearchParams(params).toString();
            const response = await fetch(`${BASE_URL}/transactions?${queryString}`, {
                headers: defaultHeaders
            });
            return handleResponse(response);
        },
        getById: async (id) => {
            const response = await fetch(`${BASE_URL}/transactions/${id}`, {
                headers: defaultHeaders
            });
            return handleResponse(response);
        }
    },
    retries: {
        list: async (params = {}) => {
            const queryString = new URLSearchParams(params).toString();
            const response = await fetch(`${BASE_URL}/retries?${queryString}`, {
                headers: defaultHeaders
            });
            return handleResponse(response);
        },
        retry: async (id) => {
            const response = await fetch(`${BASE_URL}/transactions/${id}/retry`, {
                method: 'POST',
                headers: defaultHeaders
            });
            return handleResponse(response);
        }
    },
    circuitBreakers: {
        status: async () => {
            const response = await fetch(`${BASE_URL}/circuit-breakers`, {
                headers: defaultHeaders
            });
            return handleResponse(response);
        }
    }
};
