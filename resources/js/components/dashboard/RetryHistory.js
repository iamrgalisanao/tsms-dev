/** @jsxRuntime classic */
/** @jsx React.createElement */

import React from 'react';

function RetryHistory() {
    const [retries, setRetries] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [error, setError] = React.useState(null);
    const [pagination, setPagination] = React.useState({
        currentPage: 1,
        totalPages: 1,
        perPage: 10
    });

    React.useEffect(() => {
        fetchRetryHistory();
        const interval = setInterval(fetchRetryHistory, 15000); // Refresh every 15s
        return () => clearInterval(interval);
    }, [pagination.currentPage]);

    const fetchRetryHistory = async () => {
        try {
            const response = await fetch(`/api/web/dashboard/retry-history?page=${pagination.currentPage}`, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to fetch retry history');
            const data = await response.json();
            setRetries(data.data);
            setPagination({
                currentPage: data.meta.current_page,
                totalPages: data.meta.last_page,
                perPage: data.meta.per_page
            });
            setLoading(false);
        } catch (err) {
            setError(err.message);
            setLoading(false);
        }
    };

    if (loading) {
        return React.createElement('div', { className: 'flex justify-center items-center h-32' },
            React.createElement('div', { className: 'animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500' })
        );
    }

    if (error) {
        return React.createElement('div', { className: 'text-red-600 p-4 border border-red-200 rounded' },
            'Error: ', error
        );
    }

    return React.createElement('div', null,
        // Table
        React.createElement('div', { className: 'overflow-x-auto' },
            React.createElement('table', { className: 'min-w-full divide-y divide-gray-200' },
                React.createElement('thead', { className: 'bg-gray-50' },
                    React.createElement('tr', null,
                        ['Transaction ID', 'Terminal ID', 'Attempt #', 'Status', 'Timestamp'].map(header =>
                            React.createElement('th', {
                                key: header,
                                className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'
                            }, header)
                        )
                    )
                ),
                React.createElement('tbody', { className: 'bg-white divide-y divide-gray-200' },
                    retries.map(retry =>
                        React.createElement('tr', { key: retry.id },
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                retry.transaction_id
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                retry.terminal_id
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                retry.attempt_number
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                React.createElement('span', {
                                    className: `px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                        retry.status === 'SUCCESS'
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    }`
                                }, retry.status)
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-500' },
                                new Date(retry.created_at).toLocaleString()
                            )
                        )
                    )
                )
            )
        ),
        // Pagination
        React.createElement('div', { className: 'mt-4 flex justify-between items-center' },
            React.createElement('button', {
                onClick: () => setPagination(prev => ({ ...prev, currentPage: prev.currentPage - 1 })),
                disabled: pagination.currentPage === 1,
                className: `px-4 py-2 border rounded ${
                    pagination.currentPage === 1
                        ? 'bg-gray-100 text-gray-400'
                        : 'bg-white text-blue-500 hover:bg-blue-50'
                }`
            }, 'Previous'),
            React.createElement('span', { className: 'text-sm text-gray-700' },
                `Page ${pagination.currentPage} of ${pagination.totalPages}`
            ),
            React.createElement('button', {
                onClick: () => setPagination(prev => ({ ...prev, currentPage: prev.currentPage + 1 })),
                disabled: pagination.currentPage === pagination.totalPages,
                className: `px-4 py-2 border rounded ${
                    pagination.currentPage === pagination.totalPages
                        ? 'bg-gray-100 text-gray-400'
                        : 'bg-white text-blue-500 hover:bg-blue-50'
                }`
            }, 'Next')
        )
    );
}

export default RetryHistory;
