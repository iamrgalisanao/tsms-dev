/** @jsxRuntime classic */
/** @jsx React.createElement */
import React from 'react'

// Create the component using React.createElement directly instead of JSX
// This should bypass any JSX transformation issues
const TransactionLogs = (props) => {
  const handleClick = () => {
    if (props.onSelectLog) {
      props.onSelectLog({id: 1, status: 'TEST'})
    }
  }

  return React.createElement(
    'div',
    null,
    React.createElement('h1', null, 'Transaction Logs'),
    React.createElement('p', null, 'Simplified component for troubleshooting'),
    React.createElement(
      'button',
      { onClick: handleClick },
      'Test Select Log'
    )
  )
}

// Make sure we're exporting the component properly
export { TransactionLogs as default }
