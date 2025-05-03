/** @jsxRuntime classic */
/** @jsx React.createElement */

import React from 'react';

const CircuitBreakers = () => {
    const [services, setServices] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [error, setError] = React.useState(null);
    const [filters, setFilters] = React.useState({ tenant: 'all' });

    React.useEffect(() => {
        fetchServices();
        const interval = setInterval(fetchServices, 30000); // Refresh every 30s
        return () => clearInterval(interval);
    }, [filters]);

    const fetchServices = async () => {
        try {
            const url = new URL('/api/web/dashboard/circuit-breakers', window.location.origin);
            if (filters.tenant !== 'all') {
                url.searchParams.append('tenant', filters.tenant);
            }

            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to fetch services');
            const data = await response.json();
            setServices(data.data);
            setLoading(false);
        } catch (err) {
            setError(err.message);
            setLoading(false);
        }
    };

    const resetCircuitBreaker = async (id) => {
        try {
            const response = await fetch(`/api/web/dashboard/circuit-breakers/${id}/reset`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to reset circuit breaker');
            fetchServices(); // Refresh the list
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
        // Filters
        React.createElement('div', { className: 'mb-4' },
            React.createElement('select', {
                className: 'border border-gray-300 rounded px-3 py-2',
                value: filters.tenant,
                onChange: (e) => setFilters(prev => ({ ...prev, tenant: e.target.value }))
            },
                React.createElement('option', { value: 'all' }, 'All Tenants'),
                // Add tenant options dynamically
                services.map(service => 
                    React.createElement('option', { 
                        key: service.tenant_id, 
                        value: service.tenant_id 
                    }, service.tenant_name)
                )
            )
        ),
        // Grid of service cards
        React.createElement('div', { className: 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4' },
            services.map(service => 
                React.createElement('div', { 
                    key: service.id, 
                    className: 'p-4 border rounded-lg shadow bg-white'
                },
                    React.createElement('h3', { className: 'font-bold text-lg' }, service.name),
                    React.createElement('div', { 
                        className: `mt-2 ${
                            service.status === 'OPEN' ? 'text-red-600' : 
                            service.status === 'HALF_OPEN' ? 'text-yellow-600' : 
                            'text-green-600'
                        }`
                    }, 'Status: ', service.status),
                    React.createElement('div', { className: 'mt-1' }, 
                        'Trip Count: ', service.trip_count
                    ),
                    React.createElement('div', { className: 'mt-1' }, 
                        'Last Failure: ', service.last_failure_at || 'N/A'
                    ),
                    React.createElement('button', {
                        onClick: () => resetCircuitBreaker(service.id),
                        className: 'mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600'
                    }, 'Reset')
                )
            )
        )
    );
}

export default CircuitBreakers;
