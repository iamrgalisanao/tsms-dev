/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect } from "react";
import securityService from "../../../services/securityService";

const SecurityDashboard = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [dashboardData, setDashboardData] = useState(null);
    const [filters, setFilters] = useState({
        from: null,
        to: null,
        eventType: null,
        severity: null,
    });

    useEffect(() => {
        fetchDashboardData();
    }, [filters]);

    const fetchDashboardData = async () => {
        try {
            setLoading(true);
            const response = await securityService.getDashboardData(filters);
            setDashboardData(response.data);
            setError(null);
        } catch (err) {
            console.error("Error fetching security dashboard data:", err);
            setError("Failed to load security dashboard data");
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

    const handleRefresh = () => {
        fetchDashboardData();
    };

    if (loading && !dashboardData) {
        return (
            <div className="security-dashboard p-4">
                <h2 className="text-xl font-bold mb-4">Security Dashboard</h2>
                <div className="loading-spinner">Loading dashboard data...</div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="security-dashboard p-4">
                <h2 className="text-xl font-bold mb-4">Security Dashboard</h2>
                <div className="error-message alert alert-danger">{error}</div>
                <button
                    onClick={handleRefresh}
                    className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                >
                    Retry
                </button>
            </div>
        );
    }

    // If we have dashboard data, render the dashboard
    const { event_stats, alert_stats, time_series, top_ips, top_users } =
        dashboardData || {};

    return (
        <div className="security-dashboard p-4">
            <div className="flex justify-between items-center mb-6">
                <h2 className="text-xl font-bold">Security Dashboard</h2>
                <button
                    onClick={handleRefresh}
                    className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                >
                    Refresh Data
                </button>
            </div>

            {/* Filter Controls */}
            <div className="filter-controls bg-gray-100 p-4 mb-6 rounded">
                <h3 className="text-lg font-semibold mb-2">Filters</h3>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label className="block text-sm font-medium mb-1">
                            From Date
                        </label>
                        <input
                            type="datetime-local"
                            name="from"
                            value={filters.from || ""}
                            onChange={handleFilterChange}
                            className="w-full px-3 py-2 border rounded"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">
                            To Date
                        </label>
                        <input
                            type="datetime-local"
                            name="to"
                            value={filters.to || ""}
                            onChange={handleFilterChange}
                            className="w-full px-3 py-2 border rounded"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">
                            Event Type
                        </label>
                        <select
                            name="eventType"
                            value={filters.eventType || ""}
                            onChange={handleFilterChange}
                            className="w-full px-3 py-2 border rounded"
                        >
                            <option value="">All Types</option>
                            <option value="login_failure">Login Failure</option>
                            <option value="suspicious_activity">
                                Suspicious Activity
                            </option>
                            <option value="rate_limit_breach">
                                Rate Limit Breach
                            </option>
                            <option value="circuit_breaker_trip">
                                Circuit Breaker Trip
                            </option>
                            <option value="unauthorized_access">
                                Unauthorized Access
                            </option>
                            <option value="permission_violation">
                                Permission Violation
                            </option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">
                            Severity
                        </label>
                        <select
                            name="severity"
                            value={filters.severity || ""}
                            onChange={handleFilterChange}
                            className="w-full px-3 py-2 border rounded"
                        >
                            <option value="">All Severities</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                {/* Events Summary Card */}
                <div className="bg-white p-4 rounded shadow">
                    <h3 className="text-lg font-semibold mb-3">
                        Security Events
                    </h3>
                    <p className="text-3xl font-bold mb-2">
                        {event_stats?.total_events || 0}
                    </p>
                    <div className="text-sm">
                        <div className="flex justify-between mb-1">
                            <span>Critical:</span>
                            <span className="font-semibold text-red-600">
                                {event_stats?.events_by_severity?.critical || 0}
                            </span>
                        </div>
                        <div className="flex justify-between mb-1">
                            <span>Warning:</span>
                            <span className="font-semibold text-yellow-600">
                                {event_stats?.events_by_severity?.warning || 0}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Info:</span>
                            <span className="font-semibold text-blue-600">
                                {event_stats?.events_by_severity?.info || 0}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Alert Rules Card */}
                <div className="bg-white p-4 rounded shadow">
                    <h3 className="text-lg font-semibold mb-3">Alert Rules</h3>
                    <p className="text-3xl font-bold mb-2">
                        {alert_stats?.total_alert_rules || 0}
                    </p>
                    <div className="text-sm">
                        <div className="flex justify-between mb-1">
                            <span>Active Rules:</span>
                            <span className="font-semibold">
                                {alert_stats?.active_alert_rules || 0}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Inactive Rules:</span>
                            <span className="font-semibold">
                                {(alert_stats?.total_alert_rules || 0) -
                                    (alert_stats?.active_alert_rules || 0)}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Alerts Response Card */}
                <div className="bg-white p-4 rounded shadow">
                    <h3 className="text-lg font-semibold mb-3">
                        Alert Responses
                    </h3>
                    <p className="text-3xl font-bold mb-2">
                        {alert_stats?.total_responses || 0}
                    </p>
                    <div className="text-sm">
                        <div className="flex justify-between mb-1">
                            <span>Acknowledged:</span>
                            <span className="font-semibold">
                                {alert_stats?.responses_by_status
                                    ?.acknowledged || 0}
                            </span>
                        </div>
                        <div className="flex justify-between mb-1">
                            <span>Resolved:</span>
                            <span className="font-semibold">
                                {alert_stats?.responses_by_status?.resolved ||
                                    0}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>False Positive:</span>
                            <span className="font-semibold">
                                {alert_stats?.responses_by_status
                                    ?.false_positive || 0}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Latest Events */}
            <div className="mb-6">
                <h3 className="text-lg font-semibold mb-3">
                    Latest Security Events
                </h3>
                <div className="overflow-x-auto">
                    <table className="min-w-full bg-white border">
                        <thead>
                            <tr className="bg-gray-100">
                                <th className="py-2 px-4 border">Event Type</th>
                                <th className="py-2 px-4 border">Severity</th>
                                <th className="py-2 px-4 border">Source IP</th>
                                <th className="py-2 px-4 border">Timestamp</th>
                                <th className="py-2 px-4 border">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            {event_stats?.latest_events &&
                            event_stats.latest_events.length > 0 ? (
                                event_stats.latest_events.map(
                                    (event, index) => (
                                        <tr
                                            key={index}
                                            className="hover:bg-gray-50"
                                        >
                                            <td className="py-2 px-4 border">
                                                {event.event_type}
                                            </td>
                                            <td className="py-2 px-4 border">
                                                <span
                                                    className={`inline-block px-2 py-1 rounded text-xs ${
                                                        event.severity ===
                                                        "critical"
                                                            ? "bg-red-100 text-red-800"
                                                            : event.severity ===
                                                              "warning"
                                                            ? "bg-yellow-100 text-yellow-800"
                                                            : "bg-blue-100 text-blue-800"
                                                    }`}
                                                >
                                                    {event.severity}
                                                </span>
                                            </td>
                                            <td className="py-2 px-4 border">
                                                {event.source_ip || "N/A"}
                                            </td>
                                            <td className="py-2 px-4 border">
                                                {new Date(
                                                    event.event_timestamp
                                                ).toLocaleString()}
                                            </td>
                                            <td className="py-2 px-4 border">
                                                <button
                                                    className="text-blue-500 hover:underline"
                                                    onClick={() =>
                                                        alert(
                                                            "Event details would be shown here"
                                                        )
                                                    }
                                                >
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    )
                                )
                            ) : (
                                <tr>
                                    <td
                                        colSpan="5"
                                        className="py-2 px-4 border text-center"
                                    >
                                        No events found
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="text-center mt-4">
                <p className="text-sm text-gray-500">
                    Note: This is a basic implementation of the Security
                    Dashboard. Charts and visualizations will be added in Phase
                    2.
                </p>
            </div>
        </div>
    );
};

export default SecurityDashboard;
