import React, { useState, useEffect } from "react";

// Simple StatusBadge component
function StatusBadge({ status }) {
    const colorClasses = {
        CLOSED: "bg-green-100 text-green-800",
        OPEN: "bg-red-100 text-red-800",
        HALF_OPEN: "bg-yellow-100 text-yellow-800",
    };

    return React.createElement(
        "span",
        {
            className: `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                colorClasses[status] || "bg-gray-100 text-gray-800"
            }`,
        },
        status
    );
}

function StateOverview({ tenantId, onServiceSelect }) {
    const [circuitBreakers, setCircuitBreakers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedBreaker, setSelectedBreaker] = useState(null);

    useEffect(() => {
        const fetchStates = async () => {
            try {
                setLoading(true);
                const token = localStorage.getItem("auth_token");
                const response = await fetch(
                    `/api/web/circuit-breaker/states?tenant_id=${tenantId}`,
                    {
                        credentials: "same-origin",
                        headers: {
                            Accept: "application/json",
                            "Content-Type": "application/json",
                            Authorization: `Bearer ${token}`,
                            "X-Requested-With": "XMLHttpRequest",
                        },
                    }
                );
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error("Error response body:", errorText);
                    throw new Error("Failed to fetch circuit breaker states");
                }
                const data = await response.json();
                setCircuitBreakers(data);
                setError(null);
            } catch (err) {
                setError("Failed to load circuit breaker states");
                console.error("Error fetching states:", err);
            } finally {
                setLoading(false);
            }
        };

        fetchStates();
        const interval = setInterval(fetchStates, 5000);
        return () => clearInterval(interval);
    }, [tenantId]);

    const handleBreakerClick = (breaker) => {
        setSelectedBreaker(breaker.name);
        onServiceSelect(breaker.name);
    };

    if (loading) {
        return React.createElement(
            "div",
            { className: "text-center p-4" },
            "Loading circuit breaker states..."
        );
    }

    if (error) {
        return React.createElement(
            "div",
            { className: "text-red-500 p-4 bg-red-50 rounded-lg" },
            error
        );
    }

    return React.createElement(
        "div",
        { className: "bg-white shadow rounded-lg" },
        React.createElement("div", { className: "px-4 py-5 sm:p-6" }, [
            React.createElement(
                "h3",
                {
                    key: "title",
                    className: "text-lg font-medium leading-6 text-gray-900",
                },
                "Circuit Breaker States"
            ),
            React.createElement(
                "div",
                {
                    key: "grid",
                    className:
                        "mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3",
                },
                circuitBreakers.map((breaker) =>
                    React.createElement(
                        "div",
                        {
                            key: `${breaker.tenant_id}-${breaker.name}`,
                            className: `relative rounded-lg border ${
                                selectedBreaker === breaker.name
                                    ? "border-blue-500 ring-2 ring-blue-200"
                                    : "border-gray-300"
                            } 
                                    bg-white px-6 py-5 shadow-sm hover:shadow-md transition-all duration-150 cursor-pointer
                                    hover:border-blue-300 hover:scale-[1.02] active:scale-[0.98]`,
                            onClick: () => handleBreakerClick(breaker),
                        },
                        [
                            React.createElement(
                                "div",
                                {
                                    key: "header",
                                    className:
                                        "flex justify-between items-start",
                                },
                                [
                                    React.createElement(
                                        "div",
                                        { key: "info" },
                                        [
                                            React.createElement(
                                                "h4",
                                                {
                                                    key: "name",
                                                    className:
                                                        "text-sm font-medium text-gray-900",
                                                },
                                                breaker.name
                                            ),
                                            React.createElement(
                                                "p",
                                                {
                                                    key: "tenant",
                                                    className:
                                                        "text-xs text-gray-500",
                                                },
                                                `Tenant: ${breaker.tenant_id}`
                                            ),
                                        ]
                                    ),
                                    React.createElement(StatusBadge, {
                                        key: "status",
                                        status: breaker.status,
                                    }),
                                ]
                            ),
                            React.createElement(
                                "div",
                                {
                                    key: "metrics",
                                    className: "mt-4 text-xs text-gray-500",
                                },
                                [
                                    React.createElement(
                                        "div",
                                        { key: "trip-count" },
                                        `Trip Count: ${breaker.trip_count}`
                                    ),
                                    breaker.status === "OPEN" &&
                                        breaker.cooldown_until &&
                                        React.createElement(
                                            "div",
                                            { key: "cooldown" },
                                            `Retry After: ${new Date(
                                                breaker.cooldown_until
                                            ).toLocaleTimeString()}`
                                        ),
                                ]
                            ),
                        ]
                    )
                )
            ),
        ])
    );
}

export default StateOverview;
