/** @jsxRuntime classic */
/** @jsx React.createElement */

import React, { useState, useEffect } from "react";

function CircuitBreakers() {
    const [services, setServices] = useState([]);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchCircuitBreakerStatus();
    }, []);

    const fetchCircuitBreakerStatus = async () => {
        try {
            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                .getAttribute("content");

            const response = await fetch(
                "/api/web/dashboard/circuit-breakers",
                {
                    method: "GET",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                    },
                    credentials: "same-origin",
                }
            );

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            setServices(data);
            setError(null);
        } catch (error) {
            console.error("Error fetching circuit breaker status:", error);
            setError(
                "Failed to load circuit breaker status. Please try again later."
            );
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return React.createElement("div", { className: "p-4" }, "Loading...");
    }

    if (error) {
        return React.createElement(
            "div",
            { className: "p-4 bg-red-50 border border-red-200 rounded-md" },
            React.createElement("p", { className: "text-red-600" }, error)
        );
    }

    return React.createElement(
        "div",
        { className: "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" },
        services.map((service) =>
            React.createElement(
                "div",
                {
                    key: service.id,
                    className: "p-4 bg-white shadow rounded-lg",
                },
                [
                    React.createElement(
                        "h3",
                        { className: "text-lg font-semibold mb-2" },
                        service.service_name
                    ),
                    React.createElement(
                        "div",
                        {
                            className: `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                service.state === "CLOSED"
                                    ? "bg-green-100 text-green-800"
                                    : service.state === "HALF_OPEN"
                                    ? "bg-yellow-100 text-yellow-800"
                                    : "bg-red-100 text-red-800"
                            }`,
                        },
                        service.state
                    ),
                    React.createElement(
                        "div",
                        { className: "mt-2 text-sm text-gray-600" },
                        [
                            React.createElement(
                                "p",
                                null,
                                `Last Failure: ${
                                    service.last_failure_at
                                        ? new Date(
                                              service.last_failure_at
                                          ).toLocaleString()
                                        : "None"
                                }`
                            ),
                            React.createElement(
                                "p",
                                null,
                                `Cooldown Until: ${
                                    service.cooldown_until
                                        ? new Date(
                                              service.cooldown_until
                                          ).toLocaleString()
                                        : "N/A"
                                }`
                            ),
                            React.createElement(
                                "p",
                                null,
                                `Tenant: ${service.tenant?.name || "N/A"}`
                            ),
                        ]
                    ),
                ]
            )
        )
    );
}

export default CircuitBreakers;
