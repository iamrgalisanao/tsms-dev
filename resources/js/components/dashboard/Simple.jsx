// Simple.jsx - Minimal React Component
import React from 'react'

// Using named function component with default export
function TransactionLogsSimple(props) {
  // Super simple state-free component
  return React.createElement(
    'div',
    { style: { padding: '20px', border: '1px solid #eee' } },
    React.createElement('h1', null, 'Transaction Logs'),
    React.createElement('p', null, 'Simplified for troubleshooting'),
    React.createElement(
      'button',
      { 
        onClick: () => props.onSelectLog && props.onSelectLog({id: 1, status: 'TEST'}),
        style: { padding: '8px 16px', background: '#0066cc', color: 'white', border: 'none' }
      },
      'Select Test Log'
    )
  )
}

// Explicit export
module.exports = TransactionLogsSimple
