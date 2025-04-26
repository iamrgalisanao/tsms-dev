/** @jsxRuntime classic */
/** @jsx React.createElement */
import React, { useState, useEffect } from 'react';
import axios from 'axios';

const TerminalTokens = () => {
    const [tokens, setTokens] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        terminal_id: '',
        status: 'active' // active, expired, revoked
    });
    const [newTokenForm, setNewTokenForm] = useState({
        terminal_id: '',
        expiration_days: 30,
        isOpen: false
    });

    useEffect(() => {
        const fetchTokens = async () => {
            try {
                setLoading(true);
                const response = await axios.get('/api/dashboard/tokens', {
                    params: filters
                });
                setTokens(response.data);
                setLoading(false);
            } catch (error) {
                console.error('Error fetching tokens:', error);
                setLoading(false);
            }
        };

        fetchTokens();
    }, [filters]);

    if (loading) {
        return React.createElement('div', null, 'Loading tokens...');
    }

    return React.createElement(
        'div',
        { className: 'p-4' },
        React.createElement('h2', { className: 'text-2xl font-bold mb-4' }, 'Terminal Tokens'),
        React.createElement(
            'div',
            { className: 'mb-4' },
            React.createElement(
                'button',
                {
                    className: 'bg-blue-500 text-white px-4 py-2 rounded',
                    onClick: () => setNewTokenForm(prev => ({ ...prev, isOpen: true }))
                },
                'New Token'
            )
        ),
        React.createElement(
            'div',
            { className: 'overflow-x-auto' },
            React.createElement(
                'table',
                { className: 'min-w-full divide-y divide-gray-200' },
                React.createElement(
                    'thead',
                    { className: 'bg-gray-50' },
                    React.createElement(
                        'tr',
                        null,
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Terminal ID'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Status'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Created'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Expires'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Actions')
                    )
                ),
                React.createElement(
                    'tbody',
                    { className: 'bg-white divide-y divide-gray-200' },
                    tokens.length === 0 
                        ? React.createElement(
                            'tr',
                            null,
                            React.createElement(
                                'td',
                                { colSpan: '5', className: 'px-6 py-4 text-center text-sm text-gray-500' },
                                'No tokens found'
                            )
                        )
                        : tokens.map(token => React.createElement(
                            'tr',
                            { key: token.id },
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' }, token.terminal_id),
                            React.createElement(
                                'td',
                                { className: 'px-6 py-4 whitespace-nowrap' },
                                React.createElement(
                                    'span',
                                    {
                                        className: `px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                            token.status === 'active'
                                                ? 'bg-green-100 text-green-800'
                                                : 'bg-red-100 text-red-800'
                                        }`
                                    },
                                    token.status
                                )
                            ),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' }, new Date(token.created_at).toLocaleString()),
                            React.createElement('td', { className: 'px-6 py-4 whitespace-nowrap' }, new Date(token.expires_at).toLocaleString()),
                            React.createElement(
                                'td',
                                { className: 'px-6 py-4 whitespace-nowrap text-sm text-gray-500' },
                                React.createElement(
                                    'button',
                                    {
                                        className: 'text-red-600 hover:text-red-900',
                                        onClick: () => {/* Revoke logic */}
                                    },
                                    'Revoke'
                                )
                            )
                        ))
                )
            )
        )
    );
};

export default TerminalTokens;
