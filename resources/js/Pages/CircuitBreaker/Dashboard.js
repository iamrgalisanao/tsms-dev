import React, { useState } from 'react';
import StateOverview from '../../Components/CircuitBreaker/Dashboard/StateOverview';
import MetricsChart from '../../Components/CircuitBreaker/Metrics/MetricsChart';
import Navbar from '../../Components/Layout/Navbar';

function CircuitBreakerDashboard() {
    console.log('CircuitBreakerDashboard rendering');
    const [selectedService, setSelectedService] = useState(null);
    const [selectedTenant, setSelectedTenant] = useState(1);

    const handleServiceSelect = (serviceName) => {
        console.log('Service selected:', serviceName);
        setSelectedService(serviceName);
    };

    return React.createElement(
        'div',
        { className: "min-h-screen bg-gray-100" },
        [
            React.createElement(Navbar, { key: 'navbar' }),
            React.createElement(
                'div',
                { key: 'content', className: "p-6 space-y-6" },
                [
                    // Header section
                    React.createElement(
                        'div',
                        {
                            key: 'header',
                            className: "flex justify-between items-center mb-6"
                        },
                        [
                            React.createElement(
                                'h1',
                                {
                                    key: 'title',
                                    className: "text-2xl font-semibold text-gray-900"
                                },
                                'Circuit Breaker Dashboard'
                            ),
                            React.createElement(
                                'div',
                                {
                                    key: 'filters',
                                    className: "flex space-x-4"
                                },
                                React.createElement(
                                    'select',
                                    {
                                        className: "rounded-md border-gray-300 py-1 px-2",
                                        onChange: (e) => setSelectedTenant(parseInt(e.target.value)),
                                        value: selectedTenant
                                    },
                                    [
                                        React.createElement('option', { key: '1', value: 1 }, 'Tenant 1'),
                                        React.createElement('option', { key: '2', value: 2 }, 'Tenant 2'),
                                        React.createElement('option', { key: '3', value: 3 }, 'Tenant 3')
                                    ]
                                )
                            )
                        ]
                    ),
                    // StateOverview section
                    React.createElement(
                        'div',
                        {
                            key: 'overview',
                            className: "mb-6"
                        },
                        React.createElement(StateOverview, {
                            tenantId: selectedTenant,
                            onServiceSelect: handleServiceSelect
                        })
                    ),
                    // MetricsChart section (conditional render with visual feedback)
                    selectedService &&
                        React.createElement(
                            'div',
                            {
                                key: 'metrics-section',
                                className: "mt-8 bg-white rounded-lg shadow"
                            },
                            [
                                React.createElement(
                                    'div',
                                    {
                                        key: 'metrics-header',
                                        className: "px-6 py-4 border-b border-gray-200"
                                    },
                                    [
                                        React.createElement(
                                            'h2',
                                            {
                                                key: 'metrics-title',
                                                className: "text-lg font-medium text-gray-900"
                                            },
                                            `Metrics for ${selectedService}`
                                        ),
                                        React.createElement(
                                            'p',
                                            {
                                                key: 'metrics-subtitle',
                                                className: "mt-1 text-sm text-gray-500"
                                            },
                                            `Showing real-time metrics for Tenant ${selectedTenant}`
                                        )
                                    ]
                                ),
                                React.createElement(
                                    'div',
                                    {
                                        key: 'metrics-content',
                                        className: "p-6"
                                    },
                                    React.createElement(MetricsChart, {
                                        serviceName: selectedService,
                                        tenantId: selectedTenant
                                    })
                                )
                            ]
                        )
                ]
            )
        ]
    );
}

export default CircuitBreakerDashboard;
