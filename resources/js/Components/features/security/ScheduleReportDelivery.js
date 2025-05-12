/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import securityService from "../../../services/securityService";

const ScheduleReportDelivery = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [schedules, setSchedules] = useState([]);
    const [reportTemplates, setReportTemplates] = useState([]);
    const [showForm, setShowForm] = useState(false);
    const [formData, setFormData] = useState({
        reportTemplate: "",
        name: "",
        description: "",
        frequency: "weekly", // daily, weekly, monthly
        dayOfWeek: "1", // 0-6 (Sunday-Saturday) for weekly
        dayOfMonth: "1", // 1-31 for monthly
        time: "09:00", // HH:MM format
        recipients: "",
        format: "pdf", // pdf, csv
        filters: {
            from: "",
            to: "",
            eventType: "",
            severity: ""
        }
    });

    useEffect(() => {
        fetchSchedules();
        fetchReportTemplates();
    }, []);

    const fetchSchedules = async () => {
        try {
            setLoading(true);
            // This endpoint needs to be implemented
            const response = await securityService.getScheduledReports();
            setSchedules(response.data);
            setError(null);
        } catch (err) {
            console.error("Error fetching scheduled reports:", err);
            setError("Failed to load scheduled reports");
        } finally {
            setLoading(false);
        }
    };

    const fetchReportTemplates = async () => {
        try {
            const response = await securityService.getReportTemplates();
            setReportTemplates(response.data);
        } catch (err) {
            console.error("Error fetching report templates:", err);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        try {
            setLoading(true);
            // Convert recipients string to array
            const recipientsArray = formData.recipients
                .split(',')
                .map(email => email.trim())
                .filter(email => email);
                
            const scheduleData = {
                ...formData,
                recipients: recipientsArray
            };
            
            // This endpoint needs to be implemented
            await securityService.scheduleReport(scheduleData);
            
            // Reset form and hide it
            setFormData({
                reportTemplate: "",
                name: "",
                description: "",
                frequency: "weekly",
                dayOfWeek: "1",
                dayOfMonth: "1",
                time: "09:00",
                recipients: "",
                format: "pdf",
                filters: {
                    from: "",
                    to: "",
                    eventType: "",
                    severity: ""
                }
            });
            
            setShowForm(false);
            
            // Refresh the schedules list
            fetchSchedules();
            
            setError(null);
        } catch (err) {
            console.error("Error scheduling report:", err);
            setError("Failed to schedule report");
        } finally {
            setLoading(false);
        }
    };

    const handleFormChange = (e) => {
        const { name, value } = e.target;
        
        // Handle nested form fields
        if (name.includes('.')) {
            const [parent, child] = name.split('.');
            setFormData(prev => ({
                ...prev,
                [parent]: {
                    ...prev[parent],
                    [child]: value
                }
            }));
        } else {
            setFormData(prev => ({
                ...prev,
                [name]: value
            }));
        }
    };

    const handleDelete = async (scheduleId) => {
        if (!window.confirm("Are you sure you want to delete this scheduled report?")) {
            return;
        }
        
        try {
            setLoading(true);
            // This endpoint needs to be implemented
            await securityService.deleteScheduledReport(scheduleId);
            
            // Refresh the schedules list
            fetchSchedules();
            
            setError(null);
        } catch (err) {
            console.error("Error deleting scheduled report:", err);
            setError("Failed to delete scheduled report");
        } finally {
            setLoading(false);
        }
    };

    if (loading && !schedules.length) {
        return (
            <div className="p-4">
                <h2 className="text-xl font-bold mb-4">Scheduled Report Delivery</h2>
                <div className="p-4 flex items-center justify-center">
                    <div className="spinner"></div>
                    <span className="ml-2">Loading scheduled reports...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="p-4">
            <div className="flex justify-between items-center mb-6">
                <h2 className="text-xl font-bold">Scheduled Report Delivery</h2>
                <button
                    onClick={() => setShowForm(!showForm)}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    {showForm ? "Cancel" : "Schedule New Report"}
                </button>
            </div>

            {error && (
                <div className="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <p>{error}</p>
                </div>
            )}

            {/* Schedule Form */}
            {showForm && (
                <div className="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 className="text-lg font-semibold mb-4">Schedule a Report</h3>
                    <form onSubmit={handleSubmit}>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Report Template
                                </label>
                                <select
                                    name="reportTemplate"
                                    value={formData.reportTemplate}
                                    onChange={handleFormChange}
                                    required
                                    className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="">Select a template</option>
                                    {reportTemplates.map(template => (
                                        <option key={template.id} value={template.id}>
                                            {template.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Schedule Name
                                </label>
                                <input
                                    type="text"
                                    name="name"
                                    value={formData.name}
                                    onChange={handleFormChange}
                                    required
                                    className="form-input block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                    placeholder="Daily Security Report"
                                />
                            </div>
                            
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Description
                                </label>
                                <textarea
                                    name="description"
                                    value={formData.description}
                                    onChange={handleFormChange}
                                    className="form-textarea block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                    rows="2"
                                    placeholder="Daily security report with all critical events"
                                ></textarea>
                            </div>
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Frequency
                                </label>
                                <select
                                    name="frequency"
                                    value={formData.frequency}
                                    onChange={handleFormChange}
                                    className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            
                            {formData.frequency === 'weekly' && (
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Day of Week
                                    </label>
                                    <select
                                        name="dayOfWeek"
                                        value={formData.dayOfWeek}
                                        onChange={handleFormChange}
                                        className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                    >
                                        <option value="0">Sunday</option>
                                        <option value="1">Monday</option>
                                        <option value="2">Tuesday</option>
                                        <option value="3">Wednesday</option>
                                        <option value="4">Thursday</option>
                                        <option value="5">Friday</option>
                                        <option value="6">Saturday</option>
                                    </select>
                                </div>
                            )}
                            
                            {formData.frequency === 'monthly' && (
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Day of Month
                                    </label>
                                    <select
                                        name="dayOfMonth"
                                        value={formData.dayOfMonth}
                                        onChange={handleFormChange}
                                        className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                    >
                                        {[...Array(31)].map((_, i) => (
                                            <option key={i + 1} value={i + 1}>
                                                {i + 1}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Time
                                </label>
                                <input
                                    type="time"
                                    name="time"
                                    value={formData.time}
                                    onChange={handleFormChange}
                                    required
                                    className="form-input block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                />
                            </div>
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Format
                                </label>
                                <select
                                    name="format"
                                    value={formData.format}
                                    onChange={handleFormChange}
                                    className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="pdf">PDF</option>
                                    <option value="csv">CSV</option>
                                </select>
                            </div>
                            
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Recipients (comma separated emails)
                                </label>
                                <input
                                    type="text"
                                    name="recipients"
                                    value={formData.recipients}
                                    onChange={handleFormChange}
                                    required
                                    className="form-input block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                    placeholder="security@example.com, admin@example.com"
                                />
                            </div>
                        </div>
                        
                        <h4 className="text-md font-medium mb-2 mt-4">Report Filters</h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Event Type
                                </label>
                                <select
                                    name="filters.eventType"
                                    value={formData.filters.eventType}
                                    onChange={handleFormChange}
                                    className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="">All Events</option>
                                    <option value="login_attempt">Login Attempts</option>
                                    <option value="data_access">Data Access</option>
                                    <option value="configuration_change">Configuration Change</option>
                                    <option value="security_alert">Security Alert</option>
                                </select>
                            </div>
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Severity
                                </label>
                                <select
                                    name="filters.severity"
                                    value={formData.filters.severity}
                                    onChange={handleFormChange}
                                    className="form-select block w-full border-gray-300 focus:border-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="">All Severities</option>
                                    <option value="critical">Critical</option>
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="info">Info</option>
                                </select>
                            </div>
                        </div>
                        
                        <div className="flex justify-end">
                            <button
                                type="submit"
                                className="px-4 py-2 bg-indigo-600 text-white rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Schedule Report
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Scheduled Reports List */}
            <div className="bg-white shadow overflow-hidden sm:rounded-md">
                <ul className="divide-y divide-gray-200">
                    {schedules.length ? (
                        schedules.map(schedule => (
                            <li key={schedule.id}>
                                <div className="px-4 py-4 sm:px-6">
                                    <div className="flex items-center justify-between">
                                        <div className="text-sm font-medium text-indigo-600 truncate">
                                            {schedule.name}
                                        </div>
                                        <div className="ml-2 flex-shrink-0 flex">
                                            <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                ${schedule.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>
                                                {schedule.status}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="mt-2 sm:flex sm:justify-between">
                                        <div className="sm:flex">
                                            <div className="mr-6 flex items-center text-sm text-gray-500">
                                                <span>Frequency: {schedule.frequency}</span>
                                            </div>
                                            <div className="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
                                                <span>Next run: {new Date(schedule.next_run).toLocaleString()}</span>
                                            </div>
                                        </div>
                                        <div className="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
                                            <div className="flex space-x-2">
                                                <button
                                                    onClick={() => handleDelete(schedule.id)}
                                                    className="text-red-600 hover:text-red-900"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        ))
                    ) : (
                        <li className="px-4 py-5 sm:px-6">
                            <div className="text-center text-gray-500">
                                No scheduled reports found.
                            </div>
                        </li>
                    )}
                </ul>
            </div>
        </div>
    );
};

export default ScheduleReportDelivery;
