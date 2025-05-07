import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import CircuitBreakerDashboard from './Pages/CircuitBreaker/Dashboard';

// Wrap dashboard in a container component
function App() {
    console.log('App component rendering');
    return React.createElement('div',
        { className: "min-h-screen bg-gray-100" },
        React.createElement(CircuitBreakerDashboard)
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
