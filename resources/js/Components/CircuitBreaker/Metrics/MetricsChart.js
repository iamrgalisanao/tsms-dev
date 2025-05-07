import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend
} from 'chart.js';
import { Line } from 'react-chartjs-2';

// Register ChartJS components
ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend
);

const MetricsChart = ({ serviceName, tenantId }) => {
    // Time range options
    const timeRanges = [
        { label: '1h', value: '1h', seconds: 3600 },
        { label: '6h', value: '6h', seconds: 21600 },
        { label: '12h', value: '12h', seconds: 43200 },
        { label: '24h', value: '24h', seconds: 86400 },
        { label: '7d', value: '7d', seconds: 604800 }
    ];

    // Load saved settings from localStorage
    const loadSavedSettings = () => {
        try {
            const savedPauseState = localStorage.getItem('metrics-pause-state') === 'true';
            const savedRange = localStorage.getItem('metrics-time-range');
            const savedRangeObject = timeRanges.find(r => r.value === savedRange) || timeRanges[0];
            return { savedPauseState, savedRangeObject };
        } catch (e) {
            console.warn('Failed to load saved settings:', e);
            return { savedPauseState: false, savedRangeObject: timeRanges[0] };
        }
    };

    // Initialize all state at the top
    const { savedPauseState, savedRangeObject } = loadSavedSettings();
    const [isPaused, setIsPaused] = useState(savedPauseState);
    const [selectedRange, setSelectedRange] = useState(savedRangeObject);
    const [state, setState] = useState({
        metrics: {
            labels: [],
            failureRates: [],
            responseTime: []
        },
        loading: false,
        error: null,
        lastUpdated: null,
        isRefreshing: false
    });

    // Memoize the fetch function
    const fetchMetrics = useCallback(async (showLoading = false) => {
        if (!serviceName || !tenantId) {
            console.warn('Missing required parameters:', { serviceName, tenantId });
            return;
        }
        
        try {
            // Set loading state
            setState(prev => ({ 
                ...prev, 
                loading: showLoading, 
                isRefreshing: true,
                error: null
            }));
            
            const params = new URLSearchParams({
                service: serviceName,
                tenant_id: tenantId,
                timeRange: selectedRange.seconds.toString()
            });
            
            const token = localStorage.getItem('auth_token');
            if (!token) {
                throw new Error('Authentication required');
            }

            console.log('Fetching metrics with params:', Object.fromEntries(params));
            const response = await fetch(`/api/web/circuit-breaker/metrics?${params}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch metrics');
            }

            const data = await response.json();
            
            // Update state with new data
            setState(prev => ({
                ...prev,
                metrics: {
                    labels: data.timestamps || [],
                    failureRates: data.failure_rates || [],
                    responseTime: data.response_times || []
                },
                lastUpdated: new Date(),
                error: null,
                loading: false,
                isRefreshing: false
            }));
        } catch (err) {
            console.error('Failed to fetch metrics:', err);
            setState(prev => ({
                ...prev,
                error: err.message,
                loading: false,
                isRefreshing: false
            }));
        }
    }, [serviceName, tenantId, selectedRange]);

    // Effect for initial load
    useEffect(() => {
        if (serviceName && tenantId) {
            fetchMetrics(true);
        }
    }, [serviceName, tenantId]); // Deliberately omit fetchMetrics

    // Effect for time range changes
    useEffect(() => {
        if (serviceName && tenantId) {
            fetchMetrics(true);
        }
    }, [selectedRange]); // Deliberately omit fetchMetrics

    // Effect for polling
    useEffect(() => {
        let intervalId = null;

        if (!isPaused && serviceName && tenantId) {
            intervalId = setInterval(() => {
                fetchMetrics(false);
            }, 60000);
        }

        return () => {
            if (intervalId) {
                clearInterval(intervalId);
            }
        };
    }, [isPaused, serviceName, tenantId, fetchMetrics]);

    // Chart options
    const options = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        animation: {
            duration: 0
        },
        elements: {
            line: {
                tension: 0
            },
            point: {
                radius: 0,
                hoverRadius: 4
            }
        },
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: `Service Metrics - ${serviceName}`
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Failure Rate (%)'
                },
                ticks: {
                    callback: (value) => `${value}%`
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Response Time (ms)'
                },
                ticks: {
                    callback: (value) => `${value}ms`
                },
                grid: {
                    drawOnChartArea: false
                }
            }
        }
    }), [serviceName]);

    // Chart data
    const chartData = useMemo(() => ({
        labels: state.metrics.labels,
        datasets: [
            {
                label: 'Failure Rate',
                data: state.metrics.failureRates,
                borderColor: 'rgb(239, 68, 68)',
                backgroundColor: 'rgba(239, 68, 68, 0.5)',
                yAxisID: 'y'
            },
            {
                label: 'Response Time',
                data: state.metrics.responseTime,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                yAxisID: 'y1'
            }
        ]
    }), [state.metrics]);

    // Render loading state
    if (state.loading) {
        return React.createElement('div',
            { className: 'bg-white p-4 rounded-lg shadow' },
            React.createElement('div',
                { className: 'text-center p-8' },
                [
                    React.createElement('div',
                        { 
                            key: 'loading-spinner',
                            className: 'inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900'
                        }
                    ),
                    React.createElement('div',
                        {
                            key: 'loading-text',
                            className: 'mt-2 text-gray-600'
                        },
                        'Loading metrics...'
                    )
                ]
            )
        );
    }

    // Render error state
    if (state.error) {
        return React.createElement('div',
            { className: 'bg-white p-4 rounded-lg shadow' },
            React.createElement('div',
                { className: 'bg-red-50 text-red-600 p-4 rounded-lg' },
                [
                    React.createElement('div',
                        {
                            key: 'error-text',
                            className: 'mb-2'
                        },
                        `Error: ${state.error}`
                    ),
                    React.createElement('button',
                        {
                            key: 'retry-button',
                            className: 'px-3 py-1 bg-red-100 hover:bg-red-200 text-red-700 rounded-md text-sm',
                            onClick: () => fetchMetrics(true)
                        },
                        'Retry'
                    )
                ]
            )
        );
    }

    // Render empty state
    if (!state.metrics.labels.length) {
        return React.createElement('div',
            { className: 'bg-white p-4 rounded-lg shadow text-center text-gray-500' },
            'No metrics data available'
        );
    }

    // Render chart
    return React.createElement('div',
        { className: 'bg-white p-4 rounded-lg shadow' },
        [
            // Controls
            React.createElement('div',
                {
                    key: 'controls',
                    className: 'flex items-center justify-between mb-4 px-4 py-2 bg-gray-50 rounded-lg'
                },
                [
                    React.createElement('div',
                        {
                            key: 'left-controls',
                            className: 'flex items-center space-x-4'
                        },
                        [
                            React.createElement('div',
                                {
                                    key: 'update-info',
                                    className: 'text-sm text-gray-600'
                                },
                                `Last updated: ${state.lastUpdated ? state.lastUpdated.toLocaleTimeString() : 'Never'}`
                            ),
                            React.createElement('select',
                                {
                                    key: 'time-range',
                                    className: 'text-sm border-gray-300 rounded-md shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50',
                                    value: selectedRange.value,
                                    onChange: (e) => {
                                        const range = timeRanges.find(r => r.value === e.target.value);
                                        setSelectedRange(range);
                                        localStorage.setItem('metrics-time-range', range.value);
                                    }
                                },
                                timeRanges.map(range =>
                                    React.createElement('option',
                                        {
                                            key: range.value,
                                            value: range.value
                                        },
                                        range.label
                                    )
                                )
                            )
                        ]
                    ),
                    React.createElement('div',
                        {
                            key: 'right-controls',
                            className: 'flex items-center space-x-2'
                        },
                        [
                            React.createElement('button',
                                {
                                    key: 'refresh',
                                    className: `px-3 py-1 rounded-md text-sm font-medium 
                                        ${state.isRefreshing 
                                            ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            : 'bg-blue-50 text-blue-600 hover:bg-blue-100'}`,
                                    onClick: () => fetchMetrics(true),
                                    disabled: state.isRefreshing
                                },
                                state.isRefreshing ? 'Refreshing...' : 'Refresh'
                            ),
                            React.createElement('button',
                                {
                                    key: 'pause-resume',
                                    className: `px-3 py-1 rounded-md text-sm font-medium flex items-center space-x-1
                                        ${isPaused
                                            ? 'bg-green-50 text-green-600 hover:bg-green-100'
                                            : 'bg-yellow-50 text-yellow-600 hover:bg-yellow-100'}`,
                                    onClick: () => {
                                        const newPausedState = !isPaused;
                                        setIsPaused(newPausedState);
                                        localStorage.setItem('metrics-pause-state', String(newPausedState));
                                        if (isPaused) {
                                            fetchMetrics(true);
                                        }
                                    }
                                },
                                [
                                    React.createElement('span', 
                                        { key: 'icon' }, 
                                        isPaused ? '▶️' : '⏸️'
                                    ),
                                    React.createElement('span', 
                                        { key: 'text' }, 
                                        isPaused ? 'Resume' : 'Pause'
                                    )
                                ]
                            )
                        ]
                    )
                ]
            ),
            // Chart
            React.createElement('div',
                {
                    key: 'chart',
                    className: 'h-[400px] relative'
                },
                React.createElement(Line,
                    {
                        options,
                        data: chartData
                    }
                )
            ),
            // Metrics summary
            React.createElement('div',
                {
                    key: 'summary',
                    className: 'mt-4 grid grid-cols-2 gap-4 text-sm border-t pt-4'
                },
                [
                    React.createElement('div',
                        { 
                            key: 'failure-rate',
                            className: 'p-2 rounded bg-red-50'
                        },
                        `Current Failure Rate: ${state.metrics.failureRates[state.metrics.failureRates.length - 1] || 0}%`
                    ),
                    React.createElement('div',
                        { 
                            key: 'response-time',
                            className: 'p-2 rounded bg-blue-50'
                        },
                        `Latest Response Time: ${state.metrics.responseTime[state.metrics.responseTime.length - 1] || 0}ms`
                    )
                ]
            )
        ]
    );
}

// Explicitly export the function component
const MetricsChartComponent = MetricsChart;
export default MetricsChartComponent;
