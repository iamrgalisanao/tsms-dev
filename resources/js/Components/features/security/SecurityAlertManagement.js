/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect } from "react";
import securityService from "../../../services/securityService";

const SecurityAlertManagement = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [alerts, setAlerts] = useState([]);
    const [selectedAlert, setSelectedAlert] = useState(null);
    const [noteText, setNoteText] = useState("");
    const [filter, setFilter] = useState({
        status: "active", // 'active', 'acknowledged', 'resolved', 'all'
        severity: "",
        from: "",
        to: "",
        search: "",
    });
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(15);
    const [totalAlerts, setTotalAlerts] = useState(0);

    useEffect(() => {
        fetchAlerts();
    }, [filter, page, perPage]);

    const fetchAlerts = async () => {
        try {
            setLoading(true);
            const response = await securityService.getAlerts({
                ...filter,
                page,
                perPage,
            });
            setAlerts(response.data.data);
            setTotalAlerts(response.data.total);
            setError(null);
        } catch (err) {
            console.error("Error fetching security alerts:", err);
            setError("Failed to load security alerts");
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (e) => {
        const { name, value } = e.target;
        setFilter((prev) => ({
            ...prev,
            [name]: value,
        }));
        setPage(1); // Reset to first page on filter change
    };

    const handleAlertSelect = async (alert) => {
        try {
            const response = await securityService.getAlertDetails(alert.id);
            setSelectedAlert(response.data);
            setNoteText("");
        } catch (err) {
            console.error("Error fetching alert details:", err);
            setError("Failed to load alert details");
        }
    };

    const handleCloseDetails = () => {
        setSelectedAlert(null);
    };

    const handleAcknowledgeAlert = async () => {
        if (!selectedAlert) return;

        try {
            setLoading(true);
            await securityService.acknowledgeAlert(selectedAlert.id);
            // Update the alert in state
            const updatedAlert = {
                ...selectedAlert,
                status: "acknowledged",
                acknowledged_at: new Date().toISOString(),
            };
            setSelectedAlert(updatedAlert);

            // Update the alert in the list
            setAlerts(
                alerts.map((alert) =>
                    alert.id === selectedAlert.id ? updatedAlert : alert
                )
            );

            setError(null);
        } catch (err) {
            console.error("Error acknowledging alert:", err);
            setError("Failed to acknowledge alert");
        } finally {
            setLoading(false);
        }
    };

    const handleResolveAlert = async () => {
        if (!selectedAlert) return;

        try {
            setLoading(true);
            await securityService.resolveAlert(selectedAlert.id);
            // Update the alert in state
            const updatedAlert = {
                ...selectedAlert,
                status: "resolved",
                resolved_at: new Date().toISOString(),
            };
            setSelectedAlert(updatedAlert);

            // Update the alert in the list
            setAlerts(
                alerts.map((alert) =>
                    alert.id === selectedAlert.id ? updatedAlert : alert
                )
            );

            setError(null);
        } catch (err) {
            console.error("Error resolving alert:", err);
            setError("Failed to resolve alert");
        } finally {
            setLoading(false);
        }
    };

    const handleAddNote = async (e) => {
        e.preventDefault();
        if (!selectedAlert || !noteText.trim()) return;

        try {
            setLoading(true);
            const response = await securityService.addAlertNote(
                selectedAlert.id,
                { content: noteText }
            );

            // Update selected alert with the new note
            const updatedNotes = [
                ...(selectedAlert.notes || []),
                response.data,
            ];
            setSelectedAlert({
                ...selectedAlert,
                notes: updatedNotes,
            });

            setNoteText("");
            setError(null);
        } catch (err) {
            console.error("Error adding note to alert:", err);
            setError("Failed to add note");
        } finally {
            setLoading(false);
        }
    };

    const handlePageChange = (newPage) => {
        setPage(newPage);
    };

    const getSeverityClass = (severity) => {
        switch (severity.toLowerCase()) {
            case "critical":
                return "bg-red-100 text-red-800 border-red-300";
            case "high":
                return "bg-orange-100 text-orange-800 border-orange-300";
            case "medium":
                return "bg-yellow-100 text-yellow-800 border-yellow-300";
            case "low":
                return "bg-blue-100 text-blue-800 border-blue-300";
            default:
                return "bg-gray-100 text-gray-800 border-gray-300";
        }
    };

    const getStatusClass = (status) => {
        switch (status.toLowerCase()) {
            case "active":
                return "bg-red-100 text-red-800";
            case "acknowledged":
                return "bg-yellow-100 text-yellow-800";
            case "resolved":
                return "bg-green-100 text-green-800";
            default:
                return "bg-gray-100 text-gray-800";
        }
    };

    const getRelativeTime = (timestamp) => {
        if (!timestamp) return "N/A";

        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffDay > 0) {
            return `${diffDay} day${diffDay > 1 ? "s" : ""} ago`;
        } else if (diffHour > 0) {
            return `${diffHour} hour${diffHour > 1 ? "s" : ""} ago`;
        } else if (diffMin > 0) {
            return `${diffMin} minute${diffMin > 1 ? "s" : ""} ago`;
        } else {
            return "just now";
        }
    };

    return (
        <div className="security-alert-management p-4">
            <h2 className="text-xl font-bold mb-6">
                Security Alert Management
            </h2>

            <div className="flex flex-col lg:flex-row gap-4">
                {/* Alerts List */}
                <div
                    className={`${
                        selectedAlert ? "lg:w-1/2" : "w-full"
                    } bg-white shadow-md rounded p-4`}
                >
                    {/* Filter Controls */}
                    <div className="filter-controls mb-4">
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-2">
                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    Status
                                </label>
                                <select
                                    name="status"
                                    value={filter.status}
                                    onChange={handleFilterChange}
                                    className="w-full px-3 py-1 border rounded text-sm"
                                >
                                    <option value="active">Active</option>
                                    <option value="acknowledged">
                                        Acknowledged
                                    </option>
                                    <option value="resolved">Resolved</option>
                                    <option value="all">All</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    Severity
                                </label>
                                <select
                                    name="severity"
                                    value={filter.severity}
                                    onChange={handleFilterChange}
                                    className="w-full px-3 py-1 border rounded text-sm"
                                >
                                    <option value="">All Severities</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    From
                                </label>
                                <input
                                    type="datetime-local"
                                    name="from"
                                    value={filter.from}
                                    onChange={handleFilterChange}
                                    className="w-full px-3 py-1 border rounded text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    To
                                </label>
                                <input
                                    type="datetime-local"
                                    name="to"
                                    value={filter.to}
                                    onChange={handleFilterChange}
                                    className="w-full px-3 py-1 border rounded text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    Search
                                </label>
                                <input
                                    type="text"
                                    name="search"
                                    value={filter.search}
                                    onChange={handleFilterChange}
                                    placeholder="Search alerts..."
                                    className="w-full px-3 py-1 border rounded text-sm"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Alerts Table */}
                    {loading && !alerts.length ? (
                        <div className="text-center py-4">
                            Loading alerts...
                        </div>
                    ) : error ? (
                        <div className="text-red-500 py-4">{error}</div>
                    ) : alerts.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            No alerts found matching your filters.
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="min-w-full">
                                    <thead className="bg-gray-100">
                                        <tr>
                                            <th className="py-2 px-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Alert
                                            </th>
                                            <th className="py-2 px-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Severity
                                            </th>
                                            <th className="py-2 px-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th className="py-2 px-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Time
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {alerts.map((alert) => (
                                            <tr
                                                key={alert.id}
                                                className={`hover:bg-gray-50 cursor-pointer ${
                                                    selectedAlert?.id ===
                                                    alert.id
                                                        ? "bg-blue-50"
                                                        : ""
                                                }`}
                                                onClick={() =>
                                                    handleAlertSelect(alert)
                                                }
                                            >
                                                <td className="py-2 px-3 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">
                                                        {alert.title}
                                                    </div>
                                                    <div className="text-xs text-gray-500 truncate max-w-xs">
                                                        {alert.description}
                                                    </div>
                                                </td>
                                                <td className="py-2 px-3 whitespace-nowrap">
                                                    <span
                                                        className={`px-2 py-1 text-xs rounded-full border ${getSeverityClass(
                                                            alert.severity
                                                        )}`}
                                                    >
                                                        {alert.severity}
                                                    </span>
                                                </td>
                                                <td className="py-2 px-3 whitespace-nowrap">
                                                    <span
                                                        className={`px-2 py-1 text-xs rounded-full ${getStatusClass(
                                                            alert.status
                                                        )}`}
                                                    >
                                                        {alert.status}
                                                    </span>
                                                </td>
                                                <td className="py-2 px-3 whitespace-nowrap text-sm text-gray-500">
                                                    {getRelativeTime(
                                                        alert.created_at
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {totalAlerts > perPage && (
                                <div className="flex justify-between items-center mt-4 text-sm">
                                    <div>
                                        Showing {(page - 1) * perPage + 1} to{" "}
                                        {Math.min(page * perPage, totalAlerts)}{" "}
                                        of {totalAlerts} alerts
                                    </div>
                                    <div className="flex space-x-2">
                                        <button
                                            onClick={() =>
                                                handlePageChange(page - 1)
                                            }
                                            disabled={page === 1}
                                            className={`px-3 py-1 rounded text-xs ${
                                                page === 1
                                                    ? "bg-gray-200 text-gray-500"
                                                    : "bg-gray-300 hover:bg-gray-400 text-gray-700"
                                            }`}
                                        >
                                            Previous
                                        </button>
                                        <button
                                            onClick={() =>
                                                handlePageChange(page + 1)
                                            }
                                            disabled={
                                                page * perPage >= totalAlerts
                                            }
                                            className={`px-3 py-1 rounded text-xs ${
                                                page * perPage >= totalAlerts
                                                    ? "bg-gray-200 text-gray-500"
                                                    : "bg-gray-300 hover:bg-gray-400 text-gray-700"
                                            }`}
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>

                {/* Alert Details */}
                {selectedAlert && (
                    <div className="lg:w-1/2 bg-white shadow-md rounded p-4">
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-semibold">
                                Alert Details
                            </h3>
                            <button
                                onClick={handleCloseDetails}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                <span className="text-xl">&times;</span>
                            </button>
                        </div>

                        <div className="mb-4">
                            <div className="flex justify-between items-start">
                                <h4 className="text-lg font-medium text-gray-900">
                                    {selectedAlert.title}
                                </h4>
                                <span
                                    className={`px-2 py-1 text-xs rounded-full ${getStatusClass(
                                        selectedAlert.status
                                    )}`}
                                >
                                    {selectedAlert.status}
                                </span>
                            </div>
                            <p className="text-sm text-gray-600 mt-1">
                                {selectedAlert.description}
                            </p>
                        </div>

                        <div className="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label className="text-xs font-medium text-gray-500">
                                    Severity
                                </label>
                                <p className="text-sm">
                                    <span
                                        className={`px-2 py-1 text-xs rounded-full border ${getSeverityClass(
                                            selectedAlert.severity
                                        )}`}
                                    >
                                        {selectedAlert.severity}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-gray-500">
                                    Time Detected
                                </label>
                                <p className="text-sm">
                                    {new Date(
                                        selectedAlert.created_at
                                    ).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-gray-500">
                                    Source
                                </label>
                                <p className="text-sm">
                                    {selectedAlert.source || "N/A"}
                                </p>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-gray-500">
                                    Tenant
                                </label>
                                <p className="text-sm">
                                    {selectedAlert.tenant?.name || "System"}
                                </p>
                            </div>
                            {selectedAlert.acknowledged_at && (
                                <div>
                                    <label className="text-xs font-medium text-gray-500">
                                        Acknowledged
                                    </label>
                                    <p className="text-sm">
                                        {new Date(
                                            selectedAlert.acknowledged_at
                                        ).toLocaleString()}
                                    </p>
                                </div>
                            )}
                            {selectedAlert.resolved_at && (
                                <div>
                                    <label className="text-xs font-medium text-gray-500">
                                        Resolved
                                    </label>
                                    <p className="text-sm">
                                        {new Date(
                                            selectedAlert.resolved_at
                                        ).toLocaleString()}
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Alert Details */}
                        <div className="mb-4">
                            <h5 className="text-sm font-medium text-gray-700 mb-2">
                                Details
                            </h5>
                            <div className="bg-gray-50 rounded p-3 text-sm">
                                <pre className="whitespace-pre-wrap">
                                    {JSON.stringify(
                                        selectedAlert.context || {},
                                        null,
                                        2
                                    )}
                                </pre>
                            </div>
                        </div>

                        {/* Response Actions */}
                        <div className="mb-4">
                            <h5 className="text-sm font-medium text-gray-700 mb-2">
                                Actions
                            </h5>
                            <div className="flex space-x-2">
                                <button
                                    onClick={handleAcknowledgeAlert}
                                    disabled={
                                        selectedAlert.status !== "active" ||
                                        loading
                                    }
                                    className={`px-3 py-1 text-sm rounded ${
                                        selectedAlert.status !== "active"
                                            ? "bg-gray-200 text-gray-500"
                                            : "bg-blue-500 text-white hover:bg-blue-600"
                                    }`}
                                >
                                    Acknowledge
                                </button>
                                <button
                                    onClick={handleResolveAlert}
                                    disabled={
                                        selectedAlert.status === "resolved" ||
                                        loading
                                    }
                                    className={`px-3 py-1 text-sm rounded ${
                                        selectedAlert.status === "resolved"
                                            ? "bg-gray-200 text-gray-500"
                                            : "bg-green-500 text-white hover:bg-green-600"
                                    }`}
                                >
                                    Resolve
                                </button>
                            </div>
                        </div>

                        {/* Notes Section */}
                        <div>
                            <h5 className="text-sm font-medium text-gray-700 mb-2">
                                Notes & Activity
                            </h5>

                            {/* Notes List */}
                            <div className="overflow-y-auto max-h-40 mb-3 bg-gray-50 rounded p-2">
                                {!selectedAlert.notes ||
                                selectedAlert.notes.length === 0 ? (
                                    <p className="text-sm text-gray-500 italic p-1">
                                        No notes yet
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {selectedAlert.notes.map(
                                            (note, index) => (
                                                <li
                                                    key={index}
                                                    className="text-sm bg-white p-2 rounded shadow-sm"
                                                >
                                                    <div className="flex justify-between">
                                                        <span className="font-medium">
                                                            {note.user?.name ||
                                                                "System"}
                                                        </span>
                                                        <span className="text-xs text-gray-500">
                                                            {new Date(
                                                                note.created_at
                                                            ).toLocaleString()}
                                                        </span>
                                                    </div>
                                                    <p className="mt-1">
                                                        {note.content}
                                                    </p>
                                                </li>
                                            )
                                        )}
                                    </ul>
                                )}
                            </div>

                            {/* Add Note Form */}
                            <form onSubmit={handleAddNote}>
                                <textarea
                                    value={noteText}
                                    onChange={(e) =>
                                        setNoteText(e.target.value)
                                    }
                                    className="w-full px-3 py-2 border rounded text-sm mb-2"
                                    placeholder="Add a note about this alert..."
                                    rows="2"
                                    disabled={loading}
                                ></textarea>
                                <div className="flex justify-end">
                                    <button
                                        type="submit"
                                        disabled={!noteText.trim() || loading}
                                        className={`px-3 py-1 text-sm rounded ${
                                            !noteText.trim() || loading
                                                ? "bg-gray-200 text-gray-500"
                                                : "bg-blue-500 text-white hover:bg-blue-600"
                                        }`}
                                    >
                                        Add Note
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default SecurityAlertManagement;
