/** @jsxRuntime classic */
/** @jsx React.createElement */

import React from 'react';

function TerminalTokens() {
    const [tokens, setTokens] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [error, setError] = React.useState(null);

    React.useEffect(() => {
        fetchTokens();
    }, []);

    const fetchTokens = async () => {
        try {
            const response = await fetch('/api/web/dashboard/terminal-tokens', {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to fetch terminal tokens');
            const data = await response.json();
            setTokens(data.data);
            setLoading(false);
        } catch (err) {
            setError(err.message);
            setLoading(false);
        }
    };

    const regenerateToken = async (terminalId) => {
        try {
            const response = await fetch(`/api/web/dashboard/terminal-tokens/${terminalId}/regenerate`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to regenerate token');
            await fetchTokens(); // Refresh the list
        } catch (err) {
            setError(err.message);
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

    return React.createElement('div', { className: 'space-y-6' },
        React.createElement('div', { className: 'overflow-x-auto' },
            React.createElement('table', { className: 'min-w-full divide-y divide-gray-200' },
                React.createElement('thead', { className: 'bg-gray-50' },
                    React.createElement('tr', null,
                        ['Terminal ID', 'Status', 'Last Used', 'Expires At', 'Actions'].map(header =>
                            React.createElement('th', {
                                key: header,
                                className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'
                            }, header)
                        )
                    )
                ),
                React.createElement('tbody', { className: 'bg-white divide-y divide-gray-200' },
                    tokens.map(token =>
                        React.createElement('tr', { key: token.id },
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                token.terminal_id
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' },
                                React.createElement('span', {
                                    className: `px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                        token.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    }`
                                }, token.is_active ? 'Active' : 'Inactive')
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-500' },
                                token.last_used_at ? new Date(token.last_used_at).toLocaleString() : 'Never'
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-500' },
                                new Date(token.expires_at).toLocaleString()
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap text-right text-sm font-medium' },
                                React.createElement('button', {
                                    onClick: () => regenerateToken(token.terminal_id),
                                    className: 'text-blue-600 hover:text-blue-900'
                                }, 'Regenerate Token')
                            )
                        )
                    )
                )
            )
        )
    );
}

export default TerminalTokens;
