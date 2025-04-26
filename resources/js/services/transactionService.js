// API service for handling all transaction-related requests
const API_BASE = '/api/dashboard';

// Get CSRF token from meta tag
const getCSRFToken = () => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
};

const defaultHeaders = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': getCSRFToken()
};

export async function fetchTransactions(params = {}) {
    try {
        const queryString = new URLSearchParams(params).toString();
        const response = await fetch(`${API_BASE}/transactions?${queryString}`, {
            headers: defaultHeaders,
            credentials: 'same-origin' // Include cookies in the request
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || `HTTP error! status: ${response.status}`);
        }

        return data;
    } catch (error) {
        console.error('Error fetching transactions:', error);
        throw error;
    }
}

export async function retryTransaction(id) {
    try {
        const response = await fetch(`${API_BASE}/transactions/${id}/retry`, {
            method: 'POST',
            headers: defaultHeaders,
            credentials: 'same-origin' // Include cookies in the request
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || `HTTP error! status: ${response.status}`);
        }

        return data;
    } catch (error) {
        console.error('Error retrying transaction:', error);
        throw error;
    }
}
