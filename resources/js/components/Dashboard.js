/** @jsxRuntime classic */
/** @jsx React.createElement */

import React from 'react';
import TransactionLogs from './features/transactions/TransactionLogs';
import CircuitBreakers from './dashboard/CircuitBreakers';
import RetryHistory from './dashboard/RetryHistory';
import TerminalTokens from './dashboard/TerminalTokens';

function Dashboard() {
    const [activeTab, setActiveTab] = React.useState('transactions');

    const tabs = [
        { id: 'transactions', label: 'Transaction Logs', component: TransactionLogs },
        { id: 'retries', label: 'Retry History', component: RetryHistory },
        { id: 'circuit-breakers', label: 'Circuit Breaker Status', component: CircuitBreakers },
        { id: 'tokens', label: 'Terminal Tokens', component: TerminalTokens }
    ];

    return React.createElement('div', { className: 'container mx-auto px-4 py-6' },
        React.createElement('h1', { className: 'text-2xl font-bold mb-6' }, 'Transaction Monitoring Dashboard'),
        React.createElement('div', { className: 'border-b border-gray-200 mb-6' },
            React.createElement('nav', { className: '-mb-px flex space-x-8' },
                tabs.map(tab => 
                    React.createElement('button', {
                        key: tab.id,
                        onClick: () => setActiveTab(tab.id),
                        className: `${
                            activeTab === tab.id
                                ? 'border-blue-500 text-blue-600'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                        } whitespace-nowrap pb-4 px-1 border-b-2 font-medium`
                    }, tab.label)
                )
            )
        ),
        React.createElement('main', { className: 'mt-6' },
            React.createElement(tabs.find(tab => tab.id === activeTab).component)
        )
    );
}

export default Dashboard;
