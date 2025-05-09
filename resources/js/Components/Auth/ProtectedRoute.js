import React from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../../Contexts/AuthContext";

const ProtectedRoute = ({ children }) => {
    const { user, loading } = useAuth();
    const location = useLocation();

    if (loading) {
        return React.createElement(
            "div",
            { className: "min-h-screen flex items-center justify-center" },
            React.createElement("div", {
                className:
                    "animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900",
            })
        );
    }

    if (!user) {
        return React.createElement(Navigate, {
            to: "/login",
            state: { from: location },
            replace: true,
        });
    }

    return children;
};

export default ProtectedRoute;
