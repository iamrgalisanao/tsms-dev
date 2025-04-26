import React, { useState, useEffect } from 'react';
import axios from 'axios';

const CircuitBreakers = () => {
    const [circuitBreakers, setCircuitBreakers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        tenant_id: '',
        service_name: ''
    });

    useEffect(() => {
        const fetchCircuitBreakers = async () => {
            try {
                setLoading(true);
                const response = await axios.get('/api/dashboard/circuit-breakers', {
                    params: filters
                });
                setCircuitBreakers(response.data);
                setLoading(false);
            } catch (error) {
                console.error('Error fetching circuit breakers:', error);
                setLoading(false);
            }
        };

        fetchCircuitBreakers();
    }, [filters]);

    if (loading) {
        return React.createElement('div', null, 'Loading circuit breakers...');
    }

    return React.createElement(
        'div',
        { className: 'p-4' },
        React.createElement('h2', { className: 'text-2xl font-bold mb-4' }, 'Circuit Breakers'),
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
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Service'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Status'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Tenant'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Trip Count'),
                        React.createElement('th', { className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase' }, 'Last Trip')
                    )
                ),
                React.createElement(
                    'tbody',
                    { className: 'bg-white divide-y divide-gray-200' },
                    circuitBreakers.length === 0 
                        ? React.createElement(
                            'tr',
                            null,
                            React.createElement(
                                'td',
                                { 
                                    colSpan: '5',
                                    className: 'px-6 py-4 text-center text-sm text-gray-500'
                                },
                                'No circuit breakers found'
                            )
                        )
                        : circuitBreakers.map(breaker => React.createElement(
                            'tr',
                            { key: breaker.id },
                            React.createElement('td', { className: 'px-6 py-4' }, breaker.service_name),
                            React.createElement(
                                'td',
                                { className: 'px-6 py-4' },
                                React.createElement(
                                    'span',
                                    {
                                        className: `px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                            breaker.state === 'CLOSED'
                                                ? 'bg-green-100 text-green-800'
                                                : 'bg-red-100 text-red-800'
                                        }`
                                    },
                                    breaker.state
                                )
                            ),
                            React.createElement('td', { className: 'px-6 py-4' }, breaker.tenant_id || 'All'),
                            React.createElement('td', { className: 'px-6 py-4' }, breaker.trip_count),
                            React.createElement('td', { className: 'px-6 py-4' }, breaker.last_trip_at ? new Date(breaker.last_trip_at).toLocaleString() : 'Never')
                        ))
                )
            )
        )
    );
};

export default CircuitBreakers;
