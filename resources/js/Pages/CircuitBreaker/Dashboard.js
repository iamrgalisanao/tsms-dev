import React, { useState } from "react";
import Navbar from "../../Components/Layout/Navbar";
// import TransactionLogs from "../Components/features/transactions/TransactionLogs";
import TransactionLogs from "../../Components/features/transactions/TransactionLogs";
// import CircuitBreakers from "../../Pages/CircuitBreaker/Dashboard";
import CircuitBreakers from "../../Components/dashboard/CircuitBreakers";
import RetryHistory from "../../Components/dashboard/RetryHistory";
import TerminalTokens from "../../Components/dashboard/TerminalTokens";

function Dashboard() {
    const [activeTab, setActiveTab] = useState("transactions");

    const tabs = [
        {
            id: "transactions",
            label: "Transaction Logs",
            component: TransactionLogs,
        },
        {
            id: "circuit-breakers",
            label: "Circuit Breaker Status",
            component: CircuitBreakers,
        },
        {
            id: "retries",
            label: "Retry History",
            component: RetryHistory,
        },
        {
            id: "tokens",
            label: "Terminal Tokens",
            component: TerminalTokens,
        },
    ];

    const ActiveComponent = tabs.find((tab) => tab.id === activeTab)?.component;

    return (
        <div className="min-h-screen bg-gray-100">
            <Navbar />
            <div className="container mx-auto px-4 py-6">
                <div className="border-b border-gray-200 mb-6">
                    <nav className="-mb-px flex space-x-8">
                        {tabs.map((tab) => (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={`${
                                    activeTab === tab.id
                                        ? "border-blue-500 text-blue-600"
                                        : "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300"
                                } whitespace-nowrap pb-4 px-1 border-b-2 font-medium`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </nav>
                </div>
                <main className="mt-6">
                    {ActiveComponent && <ActiveComponent />}
                </main>
            </div>
        </div>
    );
}

export default Dashboard;
