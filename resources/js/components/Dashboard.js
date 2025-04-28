/** @jsxRuntime classic */
/** @jsx React.createElement */
import React, { useState } from 'react';
import BasicLogs from './dashboard/BasicLogs.js';
import RetryHistory from './dashboard/RetryHistory.js';
import CircuitBreakers from './dashboard/CircuitBreakers.js';
import TerminalTokens from './dashboard/TerminalTokens.js';
import LogDetail from './dashboard/LogDetail.js';

const Dashboard = () => {
    const [activeTab, setActiveTab] = useState('transactions');
    const [selectedLog, setSelectedLog] = useState(null);

    return React.createElement(
        'div',
        { className: 'dashboard' },
        React.createElement(
            'header',
            { className: 'mb-6' },
            React.createElement(
                'h1',
                { className: 'text-2xl font-bold text-gray-800' },
                'Transaction Monitoring Dashboard'
            ),
            React.createElement(
                'p',
                { className: 'text-sm text-gray-600' },
                'Monitor and manage transaction states, retries, and system health'
            )
        ),
        React.createElement(
            'nav',
            { className: 'dashboard-nav' },
            React.createElement(
                'ul',
                null,
                React.createElement(
                    'li',
                    { className: activeTab === 'transactions' ? 'active' : '' },
                    React.createElement(
                        'button',
                        { onClick: () => setActiveTab('transactions') },
                        'Transaction Logs'
                    )
                ),
                React.createElement(
                    'li',
                    { className: activeTab === 'retries' ? 'active' : '' },
                    React.createElement(
                        'button',
                        { onClick: () => setActiveTab('retries') },
                        'Retry History'
                    )
                ),
                React.createElement(
                    'li',
                    { className: activeTab === 'circuit-breakers' ? 'active' : '' },
                    React.createElement(
                        'button',
                        { onClick: () => setActiveTab('circuit-breakers') },
                        'Circuit Breaker Status'
                    )
                ),
                React.createElement(
                    'li',
                    { className: activeTab === 'tokens' ? 'active' : '' },
                    React.createElement(
                        'button',
                        { onClick: () => setActiveTab('tokens') },
                        'Terminal Tokens'
                    )
                )
            )
        ),
        React.createElement(
            'div',
            { className: 'dashboard-content' },
            activeTab === 'transactions' && React.createElement(BasicLogs, { onSelectLog: setSelectedLog }),
            activeTab === 'retries' && React.createElement(RetryHistory),
            activeTab === 'circuit-breakers' && React.createElement(CircuitBreakers),
            activeTab === 'tokens' && React.createElement(TerminalTokens)
        ),
        selectedLog && React.createElement(LogDetail, {
            log: selectedLog,
            onClose: () => setSelectedLog(null)
        })
    );
};

export default Dashboard;
