import React, { useState } from 'react';
// Try a different import approach with the new BasicLogs component
import * as BasicLogsModule from './dashboard/BasicLogs.jsx';
import * as RetryHistoryModule from './dashboard/RetryHistory.jsx';
import CircuitBreakers from './dashboard/CircuitBreakers';
import TerminalTokens from './dashboard/TerminalTokens';
import LogDetail from './dashboard/LogDetail';

const Dashboard = () => {
    const [activeTab, setActiveTab] = useState('transactions');
    const [selectedLog, setSelectedLog] = useState(null);

    return (
        <div className="dashboard">
            <header className="mb-6">
                <h1 className="text-2xl font-bold text-gray-800">Transaction Monitoring Dashboard</h1>
                <p className="text-sm text-gray-600">Monitor and manage transaction states, retries, and system health</p>
            </header>

            <nav className="dashboard-nav">
                <ul>
                    <li className={activeTab === 'transactions' ? 'active' : ''}>
                        <button onClick={() => setActiveTab('transactions')}>
                            Transaction Logs
                        </button>
                    </li>
                    <li className={activeTab === 'retries' ? 'active' : ''}>
                        <button onClick={() => setActiveTab('retries')}>
                            Retry History
                        </button>
                    </li>
                    <li className={activeTab === 'circuit-breakers' ? 'active' : ''}>
                        <button onClick={() => setActiveTab('circuit-breakers')}>
                            Circuit Breaker Status
                        </button>
                    </li>
                    <li className={activeTab === 'tokens' ? 'active' : ''}>
                        <button onClick={() => setActiveTab('tokens')}>
                            Terminal Tokens
                        </button>
                    </li>
                </ul>
            </nav>

            <div className="dashboard-content">
                {activeTab === 'transactions' && (
                    <BasicLogsModule.default onSelectLog={setSelectedLog} />
                )}
                {activeTab === 'retries' && <RetryHistoryModule.default />}
                {activeTab === 'circuit-breakers' && <CircuitBreakers />}
                {activeTab === 'tokens' && <TerminalTokens />}
            </div>

            {selectedLog && (
                <LogDetail log={selectedLog} onClose={() => setSelectedLog(null)} />
            )}
        </div>
    );
};

export default Dashboard;
