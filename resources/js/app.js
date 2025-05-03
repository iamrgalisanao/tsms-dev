/** @jsxRuntime classic */
/** @jsx React.createElement */

import React from 'react';
import { createRoot } from 'react-dom/client';
import './bootstrap';
import '../css/app.css';
import Dashboard from './components/Dashboard';

// Error Boundary Component
class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true };
    }

    componentDidCatch(error, errorInfo) {
        console.error('Error:', error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return React.createElement('div', null, 'Something went wrong.');
        }

        return this.props.children;
    }
}

// App component
function App() {
    const [isAuthenticated, setIsAuthenticated] = React.useState(true);

    React.useEffect(() => {
        // Check authentication status on mount
        fetch('/api/web/dashboard/transactions', {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(response => {
            if (response.status === 401) {
                setIsAuthenticated(false);
                window.location.href = '/login';
            }
        });
    }, []);

    if (!isAuthenticated) {
        return null;
    }

    return React.createElement(
        ErrorBoundary,
        null,
        React.createElement(
            'div',
            { 
                style: {
                    backgroundColor: '#f0f2f5',
                    minHeight: '100vh',
                    color: '#333'
                }
            },
            React.createElement(Dashboard)
        )
    );
    
    
}

// Mount app
const container = document.getElementById('app');
if (container) {
    const root = createRoot(container);
    root.render(React.createElement(App));
}
