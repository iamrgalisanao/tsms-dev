/** @jsxRuntime classic */
/** @jsx React.createElement */
import React from 'react';

function TransactionLogs() {
    const [logs, setLogs] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [error, setError] = React.useState(null);
    const [isAuthenticated, setIsAuthenticated] = React.useState(true);

    React.useEffect(() => {
        const abortController = new AbortController();

        const fetchLogs = async () => {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                const response = await fetch('/api/web/dashboard/transactions', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    signal: abortController.signal
                });

                if (response.status === 401) {
                    setIsAuthenticated(false);
                    // Store current location before redirect
                    const currentPath = window.location.pathname;
                    window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`;
                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                setLogs(data.data || []);
                setLoading(false);
            } catch (error) {
                if (error.name === 'AbortError') {
                    return; // Ignore abort errors
                }
                console.error('Error:', error);
                setError(error.message);
                setLoading(false);
            }
        };

        // Only fetch if authenticated
        if (isAuthenticated) {
            fetchLogs();
        }

        return () => {
            abortController.abort(); // Cleanup on unmount or re-render
        };
    }, [isAuthenticated]); // Only re-run if authentication state changes

    if (!isAuthenticated) {
        return null; // Don't render anything if not authenticated
    }

    if (loading) {
        return React.createElement('div', null, 'Loading...');
    }

    if (error) {
        return React.createElement('div', null, 'Error: ', error);
    }

    return React.createElement(
        'div',
        { className: 'p-4' },
        React.createElement('h2', { className: 'text-2xl font-bold mb-4' }, 'Transaction Logs'),
        React.createElement(
            'div',
            { className: 'bg-white shadow-sm rounded-lg overflow-hidden' },
            React.createElement(
                'table',
                { className: 'min-w-full divide-y divide-gray-200' },
                React.createElement(
                    'thead',
                    { className: 'bg-gray-50' },
                    React.createElement(
                        'tr',
                        null,
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider' }, 'ID'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider' }, 'Transaction ID'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider' }, 'Status'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider' }, 'Created At')
                    )
                ),
                React.createElement(
                    'tbody',
                    { className: 'bg-white divide-y divide-gray-200' },
                    logs.length === 0 
                        ? React.createElement(
                            'tr',
                            null,
                            React.createElement(
                                'td',
                                { colSpan: '4', className: 'px-6 py-4 text-center text-sm text-gray-500' },
                                'No transactions found'
                            )
                        )
                        : logs.map(log => React.createElement(
                            'tr',
                            { key: log.id },
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-900' }, log.id),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-900' }, log.transaction_id),
                            React.createElement(
                                'td',
                                { className: 'px-6 py-4 whitespace-nowrap' },
                                React.createElement(
                                    'span',
                                    {
                                        className: `px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                            log.status === 'SUCCESS' 
                                                ? 'bg-green-100 text-green-800'
                                                : 'bg-red-100 text-red-800'
                                        }`
                                    },
                                    log.status
                                )
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-900' }, new Date(log.created_at).toLocaleString())
                        ))
                )
            )
        )
    );
}

export default TransactionLogs;
