import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from '../Contexts/AuthContext';
import ProtectedRoute from '../Components/Auth/ProtectedRoute';
import Login from '../Pages/Auth/Login';
import CircuitBreakerDashboard from '../Pages/CircuitBreaker/Dashboard';

const App = () => {
    return React.createElement(AuthProvider, null,
        React.createElement(Router, null,
            React.createElement(Routes, null, [
                React.createElement(Route, {
                    key: 'root',
                    path: '/',
                    element: React.createElement(Navigate, { to: '/dashboard', replace: true })
                }),
                React.createElement(Route, {
                    key: 'login',
                    path: '/login',
                    element: React.createElement(Login)
                }),
                React.createElement(Route, {
                    key: 'dashboard',
                    path: '/dashboard',
                    element: React.createElement(ProtectedRoute, null,
                        React.createElement(CircuitBreakerDashboard)
                    )
                }),
                // Add more protected routes here
            ])
        )
    );
};

export default App;
