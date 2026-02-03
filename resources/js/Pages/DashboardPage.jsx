import React, { useState, useEffect, useCallback } from 'react';
import api from '../Services/api';
import MetricCard from '../Components/dashboard/MetricCard';
import TransactionChart from '../Components/dashboard/TransactionChart';
import RecentTransactionsTable from '../Components/dashboard/RecentTransactionsTable';
import AuditLogsTable from '../Components/dashboard/AuditLogsTable';
import AlertsPanel from '../Components/dashboard/AlertsPanel';
import SystemHealthMonitor from '../Components/dashboard/SystemHealthMonitor';
import RevenueByTerminalChart from '../Components/dashboard/RevenueByTerminalChart';
import FilterBar from '../Components/dashboard/FilterBar';
import NotificationToast from '../Components/dashboard/NotificationToast';

const DashboardPage = () => {
    const [metrics, setMetrics] = useState(null);
    const [chartData, setChartData] = useState(null);
    const [health, setHealth] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [refreshInterval, setRefreshInterval] = useState(30000); // 30 seconds
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [notification, setNotification] = useState(null);
    const [filters, setFilters] = useState({
        start_date: '',
        end_date: '',
        terminal_id: '',
        search: ''
    });

    const fetchDashboardData = useCallback(async (isInitial = false) => {
        try {
            if (isInitial) setLoading(true);
            setIsRefreshing(true);

            const [metricsRes, chartsRes, healthRes, tpRes, transactionsRes, auditRes] = await Promise.all([
                api.getMetrics(),
                api.getCharts(),
                api.getSystemHealth(),
                api.getTerminalPerformance(),
                api.getTransactions(1, filters),
                api.getAuditLogs(1, filters)
            ]);

            setMetrics(metricsRes);
            setChartData(chartsRes);
            setHealth(healthRes);
            setTerminalPerformance(tpRes || []);
            setRecentTransactions(transactionsRes.data || []);
            setAuditLogs(auditRes.data || []);

            // Notification Detection Logic for Phase 5
            if (healthRes && healthRes.cpu > 85) {
                setNotification({ message: 'Critical high CPU usage detected! System performance may be affected.', type: 'error' });
            } else if (healthRes && healthRes.forwarding.status === 'Offline') {
                setNotification({ message: 'Transaction forwarding is currently OFFLINE.', type: 'warning' });
            } else if (isInitial) {
                // Welcome/Status notification on startup
                setNotification({ message: 'Dashboard Command Center is active and monitoring live terminals.', type: 'success' });
            }
        } catch (error) {
            console.error('Error fetching dashboard data:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchDashboardData(true);
    }, [fetchDashboardData]);

    useEffect(() => {
        if (refreshInterval <= 0) return;
        const timer = setInterval(() => fetchDashboardData(), refreshInterval);
        return () => clearInterval(timer);
    }, [fetchDashboardData, refreshInterval]);

    const handleFilterChange = (newFilters) => {
        setFilters(newFilters);
    };

    const handleExport = () => {
        const queryParams = new URLSearchParams(filters).toString();
        window.open(`/api/dashboard/export-transactions?${queryParams}`, '_blank');
    };

    const handleForward = async (id) => {
        try {
            const result = await api.forwardTransaction(id);
            if (result.status === 'success') {
                alert('Transaction forwarded successfully!');
            } else {
                alert('Forwarding failed: ' + result.message);
            }
        } catch (error) {
            alert('Error forwarding transaction.');
        }
    };

    const currencyFormat = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);

    return (
        <div className="space-y-8 pb-12">
            {/* Header section with top-priority alerts */}
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-black text-gray-900 tracking-tight flex items-center">
                        Dashboard Command Center
                        {isRefreshing && <span className="ml-3 w-2 h-2 bg-blue-500 rounded-full animate-ping"></span>}
                    </h1>
                    <p className="text-gray-500 font-medium">Real-time terminal sales and system health monitoring.</p>
                </div>
                <div className="flex items-center space-x-3">
                    <select
                        value={refreshInterval}
                        onChange={(e) => setRefreshInterval(Number(e.target.value))}
                        className="bg-white border border-gray-200 rounded-xl px-4 py-2 text-sm font-bold text-gray-700 shadow-sm focus:ring-2 focus:ring-blue-500 outline-none"
                    >
                        <option value={0}>Manual Refresh</option>
                        <option value={10000}>Refresh every 10s</option>
                        <option value={30000}>Refresh every 30s</option>
                        <option value={60000}>Refresh every 1m</option>
                    </select>
                    <button
                        onClick={() => fetchDashboardData()}
                        className="p-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors shadow-sm"
                        title="Refresh Now"
                    >
                        🔄
                    </button>
                </div>
            </div>

            {/* Top Critical Section */}
            <AlertsPanel loading={loading} alerts={[]} />

            {/* Phase 5: Filter Bar */}
            <FilterBar
                onFilterChange={handleFilterChange}
                onExport={handleExport}
                loading={loading}
            />

            {/* Real-time Metrics Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                <MetricCard
                    title="Total Sales Today"
                    value={metrics ? currencyFormat(metrics.total_sales.current) : '₱0'}
                    trend={metrics?.total_sales.trend}
                    sparkline={metrics?.total_sales.sparkline}
                    icon="💰"
                    color="blue"
                />
                <MetricCard
                    title="Total Transactions"
                    value={metrics?.total_transactions.current || 0}
                    trend={metrics?.total_transactions.trend}
                    sparkline={metrics?.total_transactions.sparkline}
                    icon="📦"
                    color="indigo"
                />
                <MetricCard
                    title="Voided Transactions"
                    value={metrics?.voided_transactions.current || 0}
                    trend={metrics?.voided_transactions.trend}
                    icon="🚫"
                    color="red"
                />
                <MetricCard
                    title="Active Terminals"
                    value={`${metrics?.active_terminals.current || 0} / ${metrics?.active_terminals.total || 0}`}
                    icon="🖥️"
                    color="green"
                />
            </div>

            {/* Analytics & System Health Section */}
            <div className="grid grid-cols-1 lg:grid-cols-4 gap-8">
                <div className="lg:col-span-3">
                    <TransactionChart data={chartData} loading={loading} />
                </div>
                <div className="flex flex-col space-y-8">
                    <SystemHealthMonitor health={health} loading={loading} />
                    <RevenueByTerminalChart data={terminalPerformance} loading={loading} />

                    <div className="bg-gradient-to-br from-indigo-600 via-blue-700 to-blue-800 p-8 rounded-2xl shadow-xl text-white relative overflow-hidden group">
                        <div className="absolute top-0 right-0 p-4 opacity-10 transform group-hover:scale-125 transition-transform duration-700">
                            <span className="text-8xl">🚀</span>
                        </div>
                        <h4 className="font-black text-xl mb-3 relative z-10">Pro Insights</h4>
                        <p className="text-blue-100 opacity-90 leading-relaxed mb-6 relative z-10">
                            The forwarding engine is currently processing at a lower latency than average. This is an ideal time for scheduled maintenance.
                        </p>
                        <button className="bg-white/20 hover:bg-white/30 text-white px-5 py-2.5 rounded-xl font-bold transition-all backdrop-blur-sm relative z-10">
                            Learn More
                        </button>
                    </div>
                </div>
            </div>

            {/* Large Tables Section */}
            <div className="space-y-8">
                <RecentTransactionsTable
                    transactions={recentTransactions}
                    loading={loading}
                    onForward={handleForward}
                />
                <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    <div className="xl:col-span-2">
                        <AuditLogsTable
                            logs={auditLogs}
                            loading={loading}
                        />
                    </div>
                    <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <h4 className="font-bold text-gray-900 mb-6 uppercase tracking-widest text-sm">Top Performing Terminals</h4>
                        <div className="space-y-4">
                            {terminalPerformance.length === 0 ? (
                                <p className="text-gray-400 text-sm italic py-4">No terminal activity recorded today.</p>
                            ) : (
                                terminalPerformance.map((tp, i) => (
                                    <div key={i} className="flex items-center justify-between p-3 hover:bg-gray-50 rounded-xl transition-colors group">
                                        <div className="flex items-center space-x-3">
                                            <div className="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center font-bold text-xs">{i + 1}</div>
                                            <div>
                                                <p className="text-sm font-bold text-gray-900 group-hover:text-blue-700 transition-colors">{tp.trade_name}</p>
                                                <p className="text-[10px] text-gray-400 font-bold uppercase tracking-tighter">{tp.terminal_uid}</p>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-sm font-black text-gray-900">₱{new Intl.NumberFormat().format(tp.total_sales)}</p>
                                            <p className="text-[10px] text-gray-400 font-bold">{tp.transaction_count} tx</p>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
            {notification && (
                <NotificationToast
                    message={notification.message}
                    type={notification.type}
                    onClose={() => setNotification(null)}
                />
            )}
        </div>
    );
};

export default DashboardPage;
