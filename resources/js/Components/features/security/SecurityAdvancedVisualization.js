/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect, useRef } from "react";
import securityService from "../../../services/securityService";

// We'll use Chart.js for rendering
import { Chart, registerables } from "chart.js";
Chart.register(...registerables);

const SecurityAdvancedVisualization = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [visualizationData, setVisualizationData] = useState(null);
    const [visualizationType, setVisualizationType] = useState("threat_map");
    const [filters, setFilters] = useState({
        from: null,
        to: null,
        groupBy: null,
    });

    // Chart references
    const chartRef = useRef(null);
    const chartInstance = useRef(null);

    // Leaflet map reference for threat map
    const mapRef = useRef(null);
    const mapInstance = useRef(null);

    useEffect(() => {
        fetchVisualizationData();
    }, [visualizationType, filters]);

    useEffect(() => {
        // When visualization data changes, update the chart/map
        if (visualizationData) {
            renderVisualization();
        }
    }, [visualizationData]);

    const fetchVisualizationData = async () => {
        try {
            setLoading(true);
            const response = await securityService.getAdvancedVisualization(
                visualizationType,
                filters
            );
            setVisualizationData(response.data);
            setError(null);
        } catch (err) {
            console.error("Error fetching visualization data:", err);
            setError("Failed to load visualization data");
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (e) => {
        const { name, value } = e.target;
        setFilters((prevFilters) => ({
            ...prevFilters,
            [name]: value || null,
        }));
    };

    const handleVisualizationTypeChange = (e) => {
        setVisualizationType(e.target.value);
    };

    const handleRefresh = () => {
        fetchVisualizationData();
    };

    const renderVisualization = () => {
        if (!visualizationData || !visualizationData.data) return;

        // Clean up previous chart or map if it exists
        if (chartInstance.current) {
            chartInstance.current.destroy();
            chartInstance.current = null;
        }

        if (mapInstance.current) {
            mapInstance.current.remove();
            mapInstance.current = null;
        }

        switch (visualizationType) {
            case "threat_map":
                renderThreatMap();
                break;
            case "attack_vectors":
                renderAttackVectors();
                break;
            case "severity_trends":
                renderSeverityTrends();
                break;
            case "user_activity_patterns":
                renderUserActivityPatterns();
                break;
            case "correlation_matrix":
                renderCorrelationMatrix();
                break;
            default:
                console.error("Unknown visualization type:", visualizationType);
        }
    };

    const renderThreatMap = () => {
        // This would use a mapping library like Leaflet or Mapbox
        // For simplicity, we'll just display the data for now
        if (!mapRef.current) return;

        // In a real implementation, you would initialize a map here
        // and plot the threat data as markers
        const container = mapRef.current;
        container.innerHTML =
            '<div class="p-4 bg-gray-100 rounded">Threat map would be rendered here with appropriate mapping library</div>';
    };

    const renderAttackVectors = () => {
        if (!chartRef.current) return;

        const ctx = chartRef.current.getContext("2d");
        const data = visualizationData.data;

        // Extract the attack vector names and counts
        const labels = Object.keys(data);
        const counts = labels.map((label) => data[label].count);

        // Create a horizontal bar chart
        chartInstance.current = new Chart(ctx, {
            type: "bar",
            data: {
                labels: labels,
                datasets: [
                    {
                        label: "Attack Vectors",
                        data: counts,
                        backgroundColor: "rgba(54, 162, 235, 0.6)",
                        borderColor: "rgba(54, 162, 235, 1)",
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                indexAxis: "y",
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: "Security Attack Vectors Analysis",
                    },
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function (context) {
                                const vectorName = context.label;
                                const vectorData = data[vectorName];
                                return [
                                    `Success Rate: ${Math.round(
                                        vectorData.success_rate * 100
                                    )}%`,
                                    `Critical: ${
                                        vectorData.severity_distribution
                                            .critical || 0
                                    }`,
                                    `High: ${
                                        vectorData.severity_distribution.high ||
                                        0
                                    }`,
                                    `Medium: ${
                                        vectorData.severity_distribution
                                            .medium || 0
                                    }`,
                                    `Low: ${
                                        vectorData.severity_distribution.low ||
                                        0
                                    }`,
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: "Attack Type",
                        },
                    },
                    x: {
                        title: {
                            display: true,
                            text: "Event Count",
                        },
                    },
                },
            },
        });
    };

    const renderSeverityTrends = () => {
        if (!chartRef.current) return;

        const ctx = chartRef.current.getContext("2d");
        const data = visualizationData.data;
        const labels = Object.keys(data);

        // Create datasets for each severity level
        const datasets = [
            {
                label: "Critical",
                data: labels.map((date) => data[date].critical || 0),
                borderColor: "rgba(255, 99, 132, 1)",
                backgroundColor: "rgba(255, 99, 132, 0.2)",
                fill: true,
            },
            {
                label: "High",
                data: labels.map((date) => data[date].high || 0),
                borderColor: "rgba(255, 159, 64, 1)",
                backgroundColor: "rgba(255, 159, 64, 0.2)",
                fill: true,
            },
            {
                label: "Medium",
                data: labels.map((date) => data[date].medium || 0),
                borderColor: "rgba(255, 205, 86, 1)",
                backgroundColor: "rgba(255, 205, 86, 0.2)",
                fill: true,
            },
            {
                label: "Low",
                data: labels.map((date) => data[date].low || 0),
                borderColor: "rgba(75, 192, 192, 1)",
                backgroundColor: "rgba(75, 192, 192, 0.2)",
                fill: true,
            },
            {
                label: "Info",
                data: labels.map((date) => data[date].info || 0),
                borderColor: "rgba(54, 162, 235, 1)",
                backgroundColor: "rgba(54, 162, 235, 0.2)",
                fill: true,
            },
        ];

        chartInstance.current = new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: `Security Events Severity Trends (${visualizationData.interval})`,
                    },
                    tooltip: {
                        mode: "index",
                        intersect: false,
                    },
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text:
                                visualizationData.interval === "hour"
                                    ? "Hour"
                                    : visualizationData.interval === "day"
                                    ? "Date"
                                    : "Week",
                        },
                    },
                    y: {
                        stacked: false,
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: "Event Count",
                        },
                    },
                },
            },
        });
    };

    const renderUserActivityPatterns = () => {
        if (!chartRef.current) return;

        const ctx = chartRef.current.getContext("2d");
        const data = visualizationData.data;
        const userIds = Object.keys(data);

        // Get event counts for each user
        const eventCounts = userIds.map((userId) => data[userId].event_count);

        chartInstance.current = new Chart(ctx, {
            type: "bar",
            data: {
                labels: userIds,
                datasets: [
                    {
                        label: "Event Count",
                        data: eventCounts,
                        backgroundColor: "rgba(153, 102, 255, 0.6)",
                        borderColor: "rgba(153, 102, 255, 1)",
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: "User Activity Patterns",
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function (context) {
                                const userId = context.label;
                                const userData = data[userId];
                                return [
                                    `Unique IPs: ${userData.unique_ips}`,
                                    `Last Active: ${new Date(
                                        userData.last_activity
                                    ).toLocaleString()}`,
                                    `Critical Events: ${
                                        userData.severity_counts.critical || 0
                                    }`,
                                    `High Events: ${
                                        userData.severity_counts.high || 0
                                    }`,
                                    `Medium Events: ${
                                        userData.severity_counts.medium || 0
                                    }`,
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: "Security Event Count",
                        },
                    },
                },
            },
        });
    };

    const renderCorrelationMatrix = () => {
        if (!chartRef.current) return;

        const ctx = chartRef.current.getContext("2d");
        const data = visualizationData;
        const eventTypes = data.event_types;
        const matrix = data.matrix;

        // Create a dataset for the heatmap
        const datasets = [];
        const dataPoints = [];

        // Prepare data for the heatmap
        eventTypes.forEach((type1, i) => {
            eventTypes.forEach((type2, j) => {
                dataPoints.push({
                    x: j,
                    y: i,
                    v: matrix[type1][type2],
                });
            });
        });

        // Create a custom rendering function to draw the heatmap
        // In a real implementation, we would use a proper heatmap plugin
        chartInstance.current = new Chart(ctx, {
            type: "scatter",
            data: {
                datasets: [
                    {
                        label: "Correlation Matrix",
                        data: dataPoints,
                        backgroundColor: function (context) {
                            const value = context.raw.v;
                            const alpha = Math.min(1, value / 10); // Adjust for your data range
                            return `rgba(54, 162, 235, ${alpha})`;
                        },
                        pointRadius: 10,
                        pointHoverRadius: 12,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: "Security Event Correlation Matrix",
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const point = context.raw;
                                const type1 = eventTypes[point.y];
                                const type2 = eventTypes[point.x];
                                return [
                                    `${type1} ↔ ${type2}`,
                                    `Correlation: ${point.v}`,
                                ];
                            },
                        },
                    },
                    legend: {
                        display: false,
                    },
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: "Event Type",
                        },
                        ticks: {
                            callback: function (value) {
                                return eventTypes[value];
                            },
                        },
                        min: -0.5,
                        max: eventTypes.length - 0.5,
                    },
                    y: {
                        title: {
                            display: true,
                            text: "Event Type",
                        },
                        ticks: {
                            callback: function (value) {
                                return eventTypes[value];
                            },
                        },
                        min: -0.5,
                        max: eventTypes.length - 0.5,
                    },
                },
            },
        });
    };

    if (loading && !visualizationData) {
        return (
            <div className="security-visualization p-4">
                <h2 className="text-xl font-bold mb-4">
                    Advanced Security Visualization
                </h2>
                <div className="p-4 flex items-center justify-center">
                    <div className="spinner"></div>
                    <span className="ml-2">Loading visualization data...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="security-visualization p-4">
            <h2 className="text-xl font-bold mb-4">
                Advanced Security Visualization
            </h2>

            {error && (
                <div className="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <p>{error}</p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        Visualization Type
                    </label>
                    <select
                        name="visualizationType"
                        value={visualizationType}
                        onChange={handleVisualizationTypeChange}
                        className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                    >
                        <option value="threat_map">Threat Map</option>
                        <option value="attack_vectors">Attack Vectors</option>
                        <option value="severity_trends">Severity Trends</option>
                        <option value="user_activity_patterns">
                            User Activity Patterns
                        </option>
                        <option value="correlation_matrix">
                            Correlation Matrix
                        </option>
                    </select>
                </div>

                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        From Date
                    </label>
                    <input
                        type="date"
                        name="from"
                        value={filters.from || ""}
                        onChange={handleFilterChange}
                        className="form-input block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                    />
                </div>

                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        To Date
                    </label>
                    <input
                        type="date"
                        name="to"
                        value={filters.to || ""}
                        onChange={handleFilterChange}
                        className="form-input block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                    />
                </div>

                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        Group By (if applicable)
                    </label>
                    <select
                        name="groupBy"
                        value={filters.groupBy || ""}
                        onChange={handleFilterChange}
                        className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                    >
                        <option value="">Default</option>
                        <option value="hour">Hour</option>
                        <option value="day">Day</option>
                        <option value="week">Week</option>
                        <option value="month">Month</option>
                    </select>
                </div>
            </div>

            <div className="mb-4 flex justify-end">
                <button
                    onClick={handleRefresh}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Refresh
                </button>
            </div>

            <div className="visualization-container mb-8 bg-white p-4 rounded-lg shadow">
                <div className="mb-2 text-sm text-gray-500">
                    {visualizationData && visualizationData.time_range && (
                        <span>
                            Period:{" "}
                            {new Date(
                                visualizationData.time_range.from
                            ).toLocaleDateString()}{" "}
                            to{" "}
                            {new Date(
                                visualizationData.time_range.to
                            ).toLocaleDateString()}
                        </span>
                    )}
                </div>

                {visualizationType === "threat_map" ? (
                    <div
                        ref={mapRef}
                        className="map-container h-96 w-full"
                    ></div>
                ) : (
                    <div className="chart-container h-96 w-full">
                        <canvas ref={chartRef}></canvas>
                    </div>
                )}
            </div>

            {visualizationData && (
                <div className="visualization-insights bg-white p-4 rounded-lg shadow">
                    <h3 className="text-lg font-bold mb-2">Insights</h3>

                    {visualizationType === "threat_map" && (
                        <div>
                            <p>
                                Total Unique Locations:{" "}
                                {visualizationData.total_locations || 0}
                            </p>
                            <div className="mt-4 overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                IP Address
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Location
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Events
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Severity
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Last Seen
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {visualizationData.data &&
                                            visualizationData.data
                                                .slice(0, 5)
                                                .map((location, index) => (
                                                    <tr key={index}>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {location.ip}
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {location.city},{" "}
                                                            {location.country}
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {
                                                                location.event_count
                                                            }
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            <span
                                                                className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                                    location.severity ===
                                                                    "critical"
                                                                        ? "bg-red-100 text-red-800"
                                                                        : location.severity ===
                                                                          "high"
                                                                        ? "bg-orange-100 text-orange-800"
                                                                        : location.severity ===
                                                                          "medium"
                                                                        ? "bg-yellow-100 text-yellow-800"
                                                                        : location.severity ===
                                                                          "low"
                                                                        ? "bg-green-100 text-green-800"
                                                                        : "bg-blue-100 text-blue-800"
                                                                }`}
                                                            >
                                                                {
                                                                    location.severity
                                                                }
                                                            </span>
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                            {new Date(
                                                                location.last_seen
                                                            ).toLocaleString()}
                                                        </td>
                                                    </tr>
                                                ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {visualizationType === "attack_vectors" && (
                        <div>
                            <p>
                                Total Events:{" "}
                                {visualizationData.total_events || 0}
                            </p>
                            <p className="mt-2">
                                Top attack vectors by event count with their
                                success rates and severity distributions.
                            </p>
                        </div>
                    )}

                    {visualizationType === "severity_trends" && (
                        <div>
                            <p>
                                Shows the trend of security events by severity
                                level over time.
                            </p>
                            <p className="mt-2">
                                Period: {visualizationData.interval}
                            </p>
                        </div>
                    )}

                    {visualizationType === "user_activity_patterns" && (
                        <div>
                            <p>
                                Total Users:{" "}
                                {visualizationData.total_users || 0}
                            </p>
                            <p className="mt-2">
                                Top users by security event count, showing their
                                activity patterns.
                            </p>
                        </div>
                    )}

                    {visualizationType === "correlation_matrix" && (
                        <div>
                            <p>
                                Shows correlations between different types of
                                security events.
                            </p>
                            <p className="mt-2">
                                Higher values indicate stronger correlations
                                between event types.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default SecurityAdvancedVisualization;
