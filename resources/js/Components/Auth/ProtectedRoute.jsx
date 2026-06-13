import React from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../../Contexts/AuthContext";

/**
 * ProtectedRoute component - Guards routes for authentication and role-based access.
 * 
 * @param {Object} props
 * @param {React.ReactNode} props.children - The component(s) to render if authorized.
 * @param {string[]} [props.roles] - Optional array of authorized roles (e.g., ['admin', 'finance']).
 */
const ProtectedRoute = ({ children, roles = [] }) => {
    const { user, loading } = useAuth();
    const location = useLocation();

    if (loading) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-slate-50">
                <div className="flex flex-col items-center gap-4">
                    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                    <p className="text-slate-500 font-medium animate-pulse">Synchronizing Session...</p>
                </div>
            </div>
        );
    }

    // 1. Authentication Check
    if (!user) {
        return <Navigate to="/login" state={{ from: location }} replace />;
    }

    // 2. Authorization (Role) Check
    if (roles.length > 0) {
        // user.roles is an array of role names from Spatie
        const userRoles = user.roles || [];
        const hasRequiredRole = roles.some(role => userRoles.includes(role));

        if (!hasRequiredRole) {
            console.warn(`Access Denied: User roles [${userRoles.join(', ')}] do not include required roles [${roles.join(', ')}]`);
            return <Navigate to="/unauthorized" replace />;
        }
    }

    return children;
};

export default ProtectedRoute;
