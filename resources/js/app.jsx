// Explicitly import React runtime requirements
import React from 'react';
import { createRoot } from 'react-dom/client';
import './bootstrap';
import "../css/app.css";

// Mount point for React application
const App = () => {
  const [selectedLog, setSelectedLog] = React.useState(null);

  // Create a simple version first to verify rendering
  return React.createElement(
    'div',
    { 
      style: {
        padding: '20px',
        backgroundColor: '#f0f2f5',
        minHeight: '100vh'
      }
    },
    React.createElement(
      'h1',
      {
        style: {
          fontSize: '24px',
          fontWeight: 'bold',
          marginBottom: '20px',
          color: '#1a1a1a'
        }
      },
      'Transaction System Dashboard'
    ),
    React.createElement(
      'div',
      {
        style: {
          backgroundColor: 'white',
          padding: '20px',
          borderRadius: '8px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.1)'
        }
      },
      'Dashboard content will appear here'
    )
  );
};

// React 18 createRoot initialization
const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(
    React.createElement(
      React.StrictMode,
      null,
      React.createElement(App)
    )
  );
}
