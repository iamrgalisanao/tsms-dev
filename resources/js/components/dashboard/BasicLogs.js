
/** @jsxRuntime classic */
/** @jsx React.createElement */
import React from 'react';
import TransactionLogs from '../features/transactions/TransactionLogs.js';

const BasicLogs = function(props) {
  return React.createElement(
    'div',
    { className: "p-4" },
    React.createElement(TransactionLogs, { onSelectLog: props.onSelectLog })
  );
};

export default BasicLogs;
