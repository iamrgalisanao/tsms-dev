import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './Contexts/AuthContext';
import ProtectedRoute from './Components/Auth/ProtectedRoute';
import Login from './Pages/Auth/Login';
import CircuitBreakerDashboard from './Pages/CircuitBreaker/Dashboard';

// Wrap dashboard in AuthProvider and Router
function App() {
    console.log('App component rendering');
    return React.createElement(AuthProvider, null,
        React.createElement(Router, null,
            React.createElement('div', 
                { className: "min-h-screen bg-gray-100" },
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
                    })
                ])
            )
        )
    );
}

// Debug messages
console.log('1. Script loaded');

document.addEventListener('DOMContentLoaded', () => {
    console.log('2. DOM loaded');
    const container = document.getElementById('app');
    console.log('3. Container found:', !!container);

    if (container) {
        try {
            const root = createRoot(container);
            root.render(React.createElement(App));
            console.log('4. React rendered');
        } catch (error) {
            console.error('React error:', error);
        }
    }
});
