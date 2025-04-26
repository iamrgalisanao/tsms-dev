// MinimalComponent.js - Note: Using .js extension instead of .jsx
import React from 'react';

// Create a minimal React component without JSX syntax
function MinimalComponent() {
  return React.createElement(
    'div',
    { style: { padding: '20px', maxWidth: '800px', margin: '0 auto' } },
    React.createElement('h1', null, 'Transaction System - Minimal View'),
    React.createElement('p', null, 'This is a minimal version of the dashboard to troubleshoot React rendering issues.'),
    React.createElement(
      'div',
      { style: { marginTop: '20px', padding: '15px', border: '1px solid #eee', borderRadius: '5px' } },
      React.createElement('h2', null, 'Transaction Logs'),
      React.createElement('p', null, 'Sample transaction data would appear here.')
    ),
    React.createElement(
      'div',
      { style: { marginTop: '20px' } },
      React.createElement(
        'button',
        { 
          style: { 
            padding: '8px 16px',
            backgroundColor: '#0066cc', 
            color: 'white', 
            border: 'none',
            borderRadius: '4px',
            cursor: 'pointer'
          },
          onClick: () => alert('Button clicked!') 
        },
        'Test Button'
      )
    )
  );
}

// Simple named export
export default MinimalComponent;
