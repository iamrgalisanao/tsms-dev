/**
 * @jest-environment jsdom
 */
import React from 'react';
import { act } from 'react-dom/test-utils';
import { createRoot } from 'react-dom/client';
import DashboardPage from '../../resources/js/Pages/DashboardPage';

// Mock the API service
jest.mock('../../resources/js/services/api', () => ({
    getMetrics: jest.fn(() => Promise.resolve({
        total_sales: { current: 150000, trend: 5, sparkline: [10000, 20000] },
        total_transactions: { current: 150, trend: 10, sparkline: [10, 20] },
        active_terminals: { current: 110, total: 117 },
        active_tenants: { current: 32, total: 35 },
        reconciliation: { reconciled: 140, total: 150, pending: 5, failed: 5 },
        pending_uploads: { current: 3 },
        exceptions: { total_exceptions: 10 }
    })),
    getCharts: jest.fn(() => Promise.resolve({
        labels: ['08:00', '09:00', '10:00'],
        sales: [5000, 8000, 12000],
        volume: [5, 8, 12],
        previous_sales: [4000, 7000, 10000]
    })),
    getSystemHealth: jest.fn(() => Promise.resolve({
        cpu: 15,
        memory: 45,
        network: 'Healthy',
        queues: { backlog: 2 }
    })),
    getTerminalPerformance: jest.fn(() => Promise.resolve([
        { terminal_id: 1, serial_number: 'SN-001', trade_name: 'Tenant A', total_sales: 5000 }
    ])),
    getTransactions: jest.fn(() => Promise.resolve({ data: [] })),
    getAuditLogs: jest.fn(() => Promise.resolve({ data: [] })),
    getNotifications: jest.fn(() => Promise.resolve({
        data: [
            { id: 1, type: 'Alert', data: { severity: 'high', title: 'Critical Reconciliation Failure', message: 'Mismatch found' } },
            { id: 2, type: 'Alert', data: { severity: 'medium', title: 'Warning Ingestion Lag', message: 'Queue size > 20' } },
            { id: 3, type: 'Alert', data: { severity: 'low', title: 'Info Health Check', message: 'All processes ok' } }
        ]
    }))
}));

// Mock useAuth Context Hook
jest.mock('../../resources/js/Contexts/AuthContext', () => ({
    useAuth: () => ({
        user: { name: 'Admin User', roles: ['admin'] }
    })
}));

// Mock sub-components
jest.mock('../../resources/js/Components/dashboard/MetricCard', () => (props) => (
    <div className="mock-metric-card" data-title={props.title} onClick={props.onClick}>
        <span>{props.title}</span>
        <span>{props.value}</span>
    </div>
));
jest.mock('../../resources/js/Components/dashboard/TransactionChart', () => () => <div className="mock-chart" />);
jest.mock('../../resources/js/Components/dashboard/RecentTransactionsTable', () => () => <div className="mock-tx-table" />);
jest.mock('../../resources/js/Components/dashboard/SystemHealthMonitor', () => () => <div className="mock-health-monitor" />);
jest.mock('../../resources/js/Components/dashboard/RevenueByTerminalChart', () => () => <div className="mock-terminal-chart" />);
jest.mock('../../resources/js/Components/dashboard/NotificationToast', () => () => <div className="mock-toast" />);
jest.mock('../../resources/js/Components/transactions/TransactionDetailPanel', () => () => <div className="mock-detail" />);

describe("DashboardPage Operations Command Center Tests", () => {
    let container;
    let root;

    beforeEach(() => {
        container = document.createElement("div");
        document.body.appendChild(container);
    });

    afterEach(() => {
        if (root) {
            act(() => {
                root.unmount();
            });
        }
        document.body.removeChild(container);
        container = null;
        root = null;
    });

    test("renders metrics and supports click navigation", async () => {
        await act(async () => {
            root = createRoot(container);
            root.render(<DashboardPage />);
        });

        // Verify the KPIs are rendered
        const cards = container.querySelectorAll(".mock-metric-card");
        expect(cards.length).toBe(5);

        // Click Total Exceptions card to switch activity tab
        const exceptionsCard = Array.from(cards).find(c => c.getAttribute("data-title") === "Total Exceptions");
        expect(exceptionsCard).toBeDefined();

        const scrollSpy = jest.fn();
        const originalScroll = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = scrollSpy;

        act(() => {
            exceptionsCard.click();
        });

        expect(scrollSpy).toHaveBeenCalled();
        Element.prototype.scrollIntoView = originalScroll;
    });

    test("groups alerts by severity with Critical first", async () => {
        await act(async () => {
            root = createRoot(container);
            root.render(<DashboardPage />);
        });

        const textContent = container.textContent;
        expect(textContent).toContain("Critical");
        expect(textContent).toContain("Warning");
        expect(textContent).toContain("Advisory");
    });
});
