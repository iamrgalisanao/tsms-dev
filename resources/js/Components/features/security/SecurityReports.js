/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import securityService from "../../../services/securityService";

const SecurityReports = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reports, setReports] = useState([]);
    const [templates, setTemplates] = useState([]);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [reportParams, setReportParams] = useState({
        title: "",
        description: "",
        dateRange: {
            from: "",
            to: "",
        },
        filters: {
            eventType: "",
            severity: "",
            tenant: "",
        },
    });
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [totalReports, setTotalReports] = useState(0);

    useEffect(() => {
        fetchReports();
        fetchTemplates();
    }, [page, perPage]);

    const fetchReports = async () => {
        try {
            setLoading(true);
            const response = await securityService.getReports({
                page,
                perPage,
            });
            setReports(response.data.data);
            setTotalReports(response.data.total);
            setError(null);
        } catch (err) {
            console.error("Error fetching security reports:", err);
            setError("Failed to load security reports");
        } finally {
            setLoading(false);
        }
    };

    const fetchTemplates = async () => {
        try {
            const response = await securityService.getReportTemplates();
            setTemplates(response.data);
        } catch (err) {
            console.error("Error fetching report templates:", err);
        }
    };

    const handleTemplateChange = (e) => {
        const templateId = e.target.value;
        const template = templates.find((t) => t.id.toString() === templateId);
        setSelectedTemplate(template || null);

        if (template) {
            // Initialize parameters based on the template
            setReportParams((prev) => ({
                ...prev,
                title: template.name,
                description: template.description,
                filters: {
                    ...prev.filters,
                    ...template.default_filters,
                },
            }));
        }
    };

    const handleParamChange = (e) => {
        const { name, value } = e.target;
        if (name.includes(".")) {
            const [parent, child] = name.split(".");
            setReportParams((prev) => ({
                ...prev,
                [parent]: {
                    ...prev[parent],
                    [child]: value,
                },
            }));
        } else {
            setReportParams((prev) => ({
                ...prev,
                [name]: value,
            }));
        }
    };

    const handleGenerateReport = async (e) => {
        e.preventDefault();

        if (!selectedTemplate) {
            setError("Please select a report template");
            return;
        }

        try {
            setLoading(true);
            const response = await securityService.generateReport({
                template_id: selectedTemplate.id,
                title: reportParams.title,
                description: reportParams.description,
                date_range: reportParams.dateRange,
                filters: reportParams.filters,
            });

            // Add the new report to the list and refresh
            fetchReports();

            // Reset form
            setSelectedTemplate(null);
            setReportParams({
                title: "",
                description: "",
                dateRange: {
                    from: "",
                    to: "",
                },
                filters: {
                    eventType: "",
                    severity: "",
                    tenant: "",
                },
            });

            setError(null);
        } catch (err) {
            console.error("Error generating report:", err);
            setError(
                "Failed to generate report: " +
                    (err.response?.data?.message || err.message)
            );
        } finally {
            setLoading(false);
        }
    };

    const handleExportReport = async (reportId, format) => {
        try {
            setLoading(true);
            await securityService.exportReport(reportId, format);
            setError(null);
        } catch (err) {
            console.error(`Error exporting report as ${format}:`, err);
            setError(`Failed to export report as ${format}`);
        } finally {
            setLoading(false);
        }
    };

    const handlePageChange = (newPage) => {
        setPage(newPage);
    };

    const handleDeleteReport = async (reportId) => {
        if (!confirm("Are you sure you want to delete this report?")) {
            return;
        }

        try {
            setLoading(true);
            await securityService.deleteReport(reportId);
            fetchReports(); // Refresh the list
            setError(null);
        } catch (err) {
            console.error("Error deleting report:", err);
            setError("Failed to delete report");
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="security-reports p-4">
            <h2 className="text-xl font-bold mb-6">Security Reports</h2>

            {/* Report Generation Form */}
            <div className="bg-white shadow-md rounded p-4 mb-6">
                <h3 className="text-lg font-semibold mb-4">
                    Generate New Report
                </h3>
                {error && <div className="mb-4 text-red-500">{error}</div>}

                <form onSubmit={handleGenerateReport}>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">
                                Report Template
                            </label>
                            <select
                                className="w-full px-3 py-2 border rounded"
                                value={selectedTemplate?.id || ""}
                                onChange={handleTemplateChange}
                                required
                            >
                                <option value="">Select a template</option>
                                {templates.map((template) => (
                                    <option
                                        key={template.id}
                                        value={template.id}
                                    >
                                        {template.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">
                                Report Title
                            </label>
                            <input
                                type="text"
                                name="title"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.title}
                                onChange={handleParamChange}
                                required
                            />
                        </div>
                    </div>

                    <div className="mb-4">
                        <label className="block text-sm font-medium mb-1">
                            Description
                        </label>
                        <textarea
                            name="description"
                            className="w-full px-3 py-2 border rounded"
                            value={reportParams.description}
                            onChange={handleParamChange}
                            rows="2"
                        ></textarea>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">
                                From Date
                            </label>
                            <input
                                type="datetime-local"
                                name="dateRange.from"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.dateRange.from}
                                onChange={handleParamChange}
                                required
                            />
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">
                                To Date
                            </label>
                            <input
                                type="datetime-local"
                                name="dateRange.to"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.dateRange.to}
                                onChange={handleParamChange}
                                required
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">
                                Event Type
                            </label>
                            <select
                                name="filters.eventType"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.filters.eventType}
                                onChange={handleParamChange}
                            >
                                <option value="">All Event Types</option>
                                <option value="login">Login</option>
                                <option value="logout">Logout</option>
                                <option value="access">Access</option>
                                <option value="data_change">Data Change</option>
                                <option value="configuration">
                                    Configuration
                                </option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">
                                Severity
                            </label>
                            <select
                                name="filters.severity"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.filters.severity}
                                onChange={handleParamChange}
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
                                Tenant
                            </label>
                            <select
                                name="filters.tenant"
                                className="w-full px-3 py-2 border rounded"
                                value={reportParams.filters.tenant}
                                onChange={handleParamChange}
                            >
                                <option value="">All Tenants</option>
                                <option value="1">Tenant 1</option>
                                <option value="2">Tenant 2</option>
                                <option value="3">Tenant 3</option>
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                            disabled={loading}
                        >
                            {loading ? "Generating..." : "Generate Report"}
                        </button>
                    </div>
                </form>
            </div>

            {/* Reports List */}
            <div className="bg-white shadow-md rounded p-4">
                <h3 className="text-lg font-semibold mb-4">
                    Generated Reports
                </h3>

                {loading && !reports.length ? (
                    <div className="text-center py-4">Loading reports...</div>
                ) : reports.length === 0 ? (
                    <div className="text-center py-4 text-gray-500">
                        No reports have been generated yet.
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="min-w-full bg-white">
                                <thead className="bg-gray-100">
                                    <tr>
                                        <th className="py-2 px-4 border-b text-left">
                                            Title
                                        </th>
                                        <th className="py-2 px-4 border-b text-left">
                                            Date Range
                                        </th>
                                        <th className="py-2 px-4 border-b text-left">
                                            Generated
                                        </th>
                                        <th className="py-2 px-4 border-b text-left">
                                            Template
                                        </th>
                                        <th className="py-2 px-4 border-b text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reports.map((report) => (
                                        <tr
                                            key={report.id}
                                            className="hover:bg-gray-50"
                                        >
                                            <td className="py-2 px-4 border-b">
                                                <Link
                                                    to={`/security/reports/${report.id}`}
                                                    className="text-blue-500 hover:underline"
                                                >
                                                    {report.title}
                                                </Link>
                                            </td>
                                            <td className="py-2 px-4 border-b">
                                                {new Date(
                                                    report.date_range.from
                                                ).toLocaleDateString()}{" "}
                                                -{" "}
                                                {new Date(
                                                    report.date_range.to
                                                ).toLocaleDateString()}
                                            </td>
                                            <td className="py-2 px-4 border-b">
                                                {new Date(
                                                    report.created_at
                                                ).toLocaleString()}
                                            </td>
                                            <td className="py-2 px-4 border-b">
                                                {report.template.name}
                                            </td>
                                            <td className="py-2 px-4 border-b text-right">
                                                <div className="flex justify-end space-x-2">
                                                    <button
                                                        onClick={() =>
                                                            handleExportReport(
                                                                report.id,
                                                                "pdf"
                                                            )
                                                        }
                                                        className="px-3 py-1 bg-indigo-500 text-white text-sm rounded hover:bg-indigo-600"
                                                        title="Export as PDF"
                                                    >
                                                        PDF
                                                    </button>
                                                    <button
                                                        onClick={() =>
                                                            handleExportReport(
                                                                report.id,
                                                                "csv"
                                                            )
                                                        }
                                                        className="px-3 py-1 bg-green-500 text-white text-sm rounded hover:bg-green-600"
                                                        title="Export as CSV"
                                                    >
                                                        CSV
                                                    </button>
                                                    <button
                                                        onClick={() =>
                                                            handleDeleteReport(
                                                                report.id
                                                            )
                                                        }
                                                        className="px-3 py-1 bg-red-500 text-white text-sm rounded hover:bg-red-600"
                                                        title="Delete Report"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {totalReports > perPage && (
                            <div className="flex justify-between items-center mt-4">
                                <div>
                                    Showing {(page - 1) * perPage + 1} to{" "}
                                    {Math.min(page * perPage, totalReports)} of{" "}
                                    {totalReports} reports
                                </div>
                                <div className="flex space-x-2">
                                    <button
                                        onClick={() =>
                                            handlePageChange(page - 1)
                                        }
                                        disabled={page === 1}
                                        className={`px-3 py-1 rounded ${
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
                                            page * perPage >= totalReports
                                        }
                                        className={`px-3 py-1 rounded ${
                                            page * perPage >= totalReports
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
        </div>
    );
};

export default SecurityReports;
