/** @jsxRuntime classic */
/** @jsx React.createElement */

// Use ES module imports instead of require
import React from 'react';
import { createRoot } from 'react-dom/client';
import './bootstrap';
import '../css/app.css';
import TransactionLogs from './components/features/transactions/TransactionLogs.js';

// Minimal App component
function App() {
  const [selectedLog, setSelectedLog] = React.useState(null);

  return React.createElement(
    'div',
    { 
      style: {
        padding: '20px',
        backgroundColor: '#f0f2f5',
        minHeight: '100vh',
        color: '#333'
      }
    },
    React.createElement(
      'h1',
      {
        style: {
          fontSize: '24px',
          fontWeight: 'bold',
          marginBottom: '20px'
        }
      },
      'Transaction System Dashboard'
    ),
    React.createElement(TransactionLogs, {
      onSelectLog: setSelectedLog
    })
  );
}

// Initialize React app
const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(
    React.createElement(React.StrictMode, null,
      React.createElement(App)
    )
  );
}
