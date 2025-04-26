// BasicLogs.jsx - Ultra minimal component with multiple export syntaxes
import React from 'react'

// Extremely simple component with no JSX
const BasicLogs = function(props) {
  return React.createElement(
    'div',
    { style: { padding: '20px', border: '1px solid #eee' } },
    React.createElement('h1', null, 'Transaction Logs'),
    React.createElement('p', null, 'Basic version for troubleshooting'),
    React.createElement(
      'button',
      { 
        onClick: () => props.onSelectLog && props.onSelectLog({id: 1, status: 'TEST'}),
        style: { padding: '8px 16px', background: '#0066cc', color: 'white', border: 'none' }
      },
      'Test Log Selection'
    )
  )
}

// Try multiple export patterns to maximize compatibility
export default BasicLogs;     // ES modules default export
export { BasicLogs };         // ES modules named export
