/**
 * Security API service
 */

import api from "./api";

const securityService = {
    /**
     * Get security dashboard data
     *
     * @param {Object} filters - Optional filters
     * @returns {Promise} Promise object with API response
     */
    getDashboardData: (filters = {}) => {
        const queryParams = new URLSearchParams();

        if (filters.from) queryParams.append("from", filters.from);
        if (filters.to) queryParams.append("to", filters.to);
        if (filters.eventType)
            queryParams.append("event_type", filters.eventType);
        if (filters.severity) queryParams.append("severity", filters.severity);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/dashboard${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get security events summary
     *
     * @param {Object} filters - Optional filters
     * @returns {Promise} Promise object with API response
     */
    getEventsSummary: (filters = {}) => {
        const queryParams = new URLSearchParams();

        if (filters.from) queryParams.append("from", filters.from);
        if (filters.to) queryParams.append("to", filters.to);
        if (filters.eventType)
            queryParams.append("event_type", filters.eventType);
        if (filters.severity) queryParams.append("severity", filters.severity);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/dashboard/events-summary${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get security alerts summary
     *
     * @param {Object} filters - Optional filters
     * @returns {Promise} Promise object with API response
     */
    getAlertsSummary: (filters = {}) => {
        const queryParams = new URLSearchParams();

        if (filters.from) queryParams.append("from", filters.from);
        if (filters.to) queryParams.append("to", filters.to);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/dashboard/alerts-summary${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get time series metrics
     *
     * @param {string} metricType - Type of metric to retrieve
     * @param {Object} params - Additional parameters
     * @returns {Promise} Promise object with API response
     */
    getTimeSeriesMetrics: (metricType = "events_by_hour", params = {}) => {
        const queryParams = new URLSearchParams();

        queryParams.append("metric_type", metricType);
        if (params.from) queryParams.append("from", params.from);
        if (params.to) queryParams.append("to", params.to);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/dashboard/time-series${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },
    
    /**
     * Get advanced visualization data
     *
     * @param {string} visualizationType - Type of visualization (threat_map, attack_vectors, severity_trends, etc.)
     * @param {Object} params - Additional parameters
     * @returns {Promise} Promise object with API response
     */
    getAdvancedVisualization: (visualizationType = "threat_map", params = {}) => {
        const queryParams = new URLSearchParams();

        queryParams.append("visualizationType", visualizationType);
        if (params.from) queryParams.append("from", params.from);
        if (params.to) queryParams.append("to", params.to);
        if (params.groupBy) queryParams.append("groupBy", params.groupBy);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/dashboard/advanced-visualization${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get security reports list
     *
     * @param {Object} params - Optional filters
     * @returns {Promise} Promise object with API response
     */
    getReports: (params = {}) => {
        const queryParams = new URLSearchParams();

        if (params.page) queryParams.append("page", params.page);
        if (params.perPage) queryParams.append("per_page", params.perPage);
        if (params.from) queryParams.append("from", params.from);
        if (params.to) queryParams.append("to", params.to);
        if (params.templateId)
            queryParams.append("template_id", params.templateId);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/reports${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get report templates
     *
     * @returns {Promise} Promise object with API response
     */
    getReportTemplates: () => {
        return api.get("/web/security/reports/templates");
    },

    /**
     * Generate a security report
     *
     * @param {Object} reportData - Report data
     * @returns {Promise} Promise object with API response
     */
    generateReport: (reportData) => {
        return api.post("/web/security/reports", reportData);
    },

    /**
     * Get a specific report by ID
     *
     * @param {number} reportId - Report ID
     * @returns {Promise} Promise object with API response
     */
    getReport: (reportId) => {
        return api.get(`/web/security/reports/${reportId}`);
    },

    /**
     * Delete a report
     *
     * @param {number} reportId - Report ID
     * @returns {Promise} Promise object with API response
     */
    deleteReport: (reportId) => {
        return api.delete(`/web/security/reports/${reportId}`);
    },

    /**
     * Export a report as PDF or CSV
     *
     * @param {number} reportId - Report ID
     * @param {string} format - Export format ('pdf' or 'csv')
     * @returns {Promise} Promise object with API response
     */
    exportReport: (reportId, format = "pdf") => {
        return api
            .get(`/web/security/reports/${reportId}/export?format=${format}`, {
                responseType: "blob",
            })
            .then((response) => {
                // Create a blob URL and trigger a download
                const url = window.URL.createObjectURL(
                    new Blob([response.data])
                );
                const link = document.createElement("a");
                link.href = url;
                link.setAttribute(
                    "download",
                    `security-report-${reportId}.${format}`
                );
                document.body.appendChild(link);
                link.click();
                link.remove();
                return response;
            });
    },

    /**
     * Get alerts list
     *
     * @param {Object} params - Parameters for filtering alerts
     * @returns {Promise} Promise object with API response
     */
    getAlerts: (params = {}) => {
        const queryParams = new URLSearchParams();

        if (params.status) queryParams.append("status", params.status);
        if (params.severity) queryParams.append("severity", params.severity);
        if (params.from) queryParams.append("from", params.from);
        if (params.to) queryParams.append("to", params.to);
        if (params.search) queryParams.append("search", params.search);
        if (params.page) queryParams.append("page", params.page);
        if (params.perPage) queryParams.append("per_page", params.perPage);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/alerts${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Get alert details by ID
     *
     * @param {number} alertId - Alert ID
     * @returns {Promise} Promise object with API response
     */
    getAlertDetails: (alertId) => {
        return api.get(`/web/security/alerts/${alertId}`);
    },

    /**
     * Acknowledge an alert
     *
     * @param {number} alertId - Alert ID
     * @returns {Promise} Promise object with API response
     */
    acknowledgeAlert: (alertId) => {
        return api.put(`/web/security/alerts/${alertId}/acknowledge`);
    },

    /**
     * Resolve an alert
     *
     * @param {number} alertId - Alert ID
     * @returns {Promise} Promise object with API response
     */
    resolveAlert: (alertId) => {
        return api.put(`/web/security/alerts/${alertId}/resolve`);
    },

    /**
     * Add a note to an alert
     *
     * @param {number} alertId - Alert ID
     * @param {Object} noteData - Note data
     * @returns {Promise} Promise object with API response
     */
    addAlertNote: (alertId, noteData) => {
        return api.post(`/web/security/alerts/${alertId}/notes`, noteData);
    },

    /**
     * Export a report
     *
     * @param {number} reportId - ID of the report to export
     * @param {string} format - Format of the export (pdf, csv)
     * @returns {Promise} Promise object with API response
     */
    exportReport: (reportId, format = "pdf") => {
        return api.get(
            `/web/security/reports/${reportId}/export?format=${format}`
        );
    },
    
    /**
     * Schedule a report for recurring delivery
     *
     * @param {Object} scheduleData - Schedule configuration data
     * @returns {Promise} Promise object with API response
     */
    scheduleReport: (scheduleData) => {
        return api.post("/web/security/reports/schedule", scheduleData);
    },

    /**
     * Get scheduled reports
     *
     * @param {Object} filters - Optional filters
     * @returns {Promise} Promise object with API response
     */
    getScheduledReports: (filters = {}) => {
        const queryParams = new URLSearchParams();

        if (filters.status) queryParams.append("status", filters.status);
        if (filters.frequency) queryParams.append("frequency", filters.frequency);

        const queryString = queryParams.toString();
        const endpoint = `/web/security/reports/schedule${
            queryString ? "?" + queryString : ""
        }`;

        return api.get(endpoint);
    },

    /**
     * Update a scheduled report
     *
     * @param {number} scheduleId - Schedule ID
     * @param {Object} updateData - Updated schedule data
     * @returns {Promise} Promise object with API response
     */
    updateScheduledReport: (scheduleId, updateData) => {
        return api.put(`/web/security/reports/schedule/${scheduleId}`, updateData);
    },

    /**
     * Delete a scheduled report
     *
     * @param {number} scheduleId - Schedule ID
     * @returns {Promise} Promise object with API response
     */
    deleteScheduledReport: (scheduleId) => {
        return api.delete(`/web/security/reports/schedule/${scheduleId}`);
    }
};

export default securityService;
