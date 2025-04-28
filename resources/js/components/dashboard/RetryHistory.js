/** @jsxRuntime classic */
/** @jsx React.createElement */
import React, { useState, useEffect } from 'react';
import axios from 'axios';

const RetryHistory = () => {
    const [retries, setRetries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        terminal_id: '',
        from_date: '',
        to_date: '',
        page: 1
    });

    useEffect(() => {
        // Mock data for initial development - remove when API is ready
        const mockData = {
            data: [
                {
                    id: 1,
                    transaction_id: 'TRANS-12345',
                    terminal_id: 'TERM-001',
                    retry_count: 2,
                    retry_at: '2025-04-26T10:30:00',
                    status: 'SUCCESS'
                },
                {
                    id: 2,
                    transaction_id: 'TRANS-12346',
                    terminal_id: 'TERM-002', 
                    retry_count: 3,
                    retry_at: '2025-04-26T11:15:00',
                    status: 'FAILED'
                }
            ],
            meta: {
                current_page: 1,
                last_page: 1,
                total: 2
            }
        }

        // Simulate API call
        setTimeout(() => {
            setRetries(mockData)
            setLoading(false)
        }, 500)
    }, [filters])

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({
            ...prev,
            [key]: value,
            page: key === 'page' ? value : 1 // Reset page when other filters change
        }))
    }

    // Simplified render method using React.createElement instead of JSX
    return React.createElement(
        'div', 
        { className: 'retry-history' },
        React.createElement('h2', { className: 'text-xl font-semibold mb-4' }, 'Retry History Viewer'),
        
        React.createElement(
            'div',
            { className: 'filters' },
            React.createElement(
                'div',
                { className: 'filter-group' },
                React.createElement('label', null, 'Terminal ID:'),
                React.createElement('input', { 
                    type: 'text',
                    value: filters.terminal_id,
                    onChange: (e) => handleFilterChange('terminal_id', e.target.value),
                    placeholder: 'Enter terminal ID...'
                })
            )
        ),
        
        loading ? 
            React.createElement('div', { className: 'loading' }, 'Loading retry history...') :
            React.createElement(
                'div',
                { className: 'retry-table-container' },
                React.createElement(
                    'table',
                    { className: 'retry-table' },
                    React.createElement(
                        'thead',
                        null,
                        React.createElement(
                            'tr',
                            null,
                            React.createElement('th', null, 'ID'),
                            React.createElement('th', null, 'Transaction ID'),
                            React.createElement('th', null, 'Terminal ID'),
                            React.createElement('th', null, 'Retry Count'),
                            React.createElement('th', null, 'Retry Date'),
                            React.createElement('th', null, 'Status')
                        )
                    ),
                    React.createElement(
                        'tbody',
                        null,
                        retries.data && retries.data.map(retry => 
                            React.createElement(
                                'tr',
                                { key: retry.id },
                                React.createElement('td', null, retry.id),
                                React.createElement('td', null, retry.transaction_id),
                                React.createElement('td', null, retry.terminal_id),
                                React.createElement('td', null, retry.retry_count),
                                React.createElement('td', null, new Date(retry.retry_at).toLocaleString()),
                                React.createElement('td', null, retry.status)
                            )
                        )
                    )
                )
            )
    )
}

// Export the component in a way that's explicitly clear to the module system
export { RetryHistory as default }
