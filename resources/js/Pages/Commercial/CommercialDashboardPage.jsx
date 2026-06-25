import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { format, subDays, startOfMonth } from 'date-fns';
import MetricCard from '../../Components/Commercial/MetricCard';
import TransactionChart from '../../Components/dashboard/TransactionChart';
import RecentTransactionsTable from '../../Components/dashboard/RecentTransactionsTable';
import NotificationToast from '../../Components/dashboard/NotificationToast';
import { Alert, CircularProgress } from '@mui/material';
import ListAltIcon from '@mui/icons-material/ListAlt';
import TodayIcon from '@mui/icons-material/Today';
import DateRangeIcon from '@mui/icons-material/DateRange';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import HistoryIcon from '@mui/icons-material/History';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import TimelineIcon from '@mui/icons-material/Timeline';
import AnalyticsIcon from '@mui/icons-material/Analytics';
import SyncIcon from '@mui/icons-material/Sync';
import api from '../../services/api';

const CommercialDashboardPage = () => {
    const [metrics, setMetrics] = useState({
        today_gross: 0,
        this_week_total: 0,
        this_month_total: 0,
        this_year_total: 0
    });
    const [charts, setCharts] = useState({
        daily: { labels: [], sales: [], volume: [] },
        weekly: { labels: [], sales: [], volume: [] },
        monthly: { labels: [], sales: [], volume: [] },
        yearly: { labels: [], sales: [], volume: [] }
    });
    const [sectionLoading, setSectionLoading] = useState({
        daily: true,
        weekly: true,
        monthly: true,
        transactions: true
    });
    const [refreshing, setRefreshing] = useState(false);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [error, setError] = useState(null);
    const [notification, setNotification] = useState(null);
    const [refreshInterval] = useState(300000); // 5 minutes

    const fetchData = useCallback(async (isInitial = false) => {
        setRefreshing(true);
        setError(null);
        try {
            // Define date parameters for the endpoints
            const todayStr = format(new Date(), 'yyyy-MM-dd');
            const sevenDaysAgoStr = format(subDays(new Date(), 6), 'yyyy-MM-dd'); // 7 days including today
            const monthStartStr = format(startOfMonth(new Date()), 'yyyy-MM-dd');

            setSectionLoading({
                daily: true,
                weekly: true,
                monthly: true,
                transactions: true
            });

            const dashboardParams = { source: 'dashboard' };
            const markSectionDone = (section) => {
                setSectionLoading((current) => ({ ...current, [section]: false }));
            };

            const requests = [
                ['daily', axios.get('/commercial/reports/transactions/daily', { params: { date: todayStr, ...dashboardParams } })
                    .then((dailyResp) => {
                        const hourlyData = dailyResp.data?.data || [];
                        const todaySum = dailyResp.data?.summary?.gross_sales || 0;

                        setMetrics((current) => ({
                            ...current,
                            today_gross: Number(todaySum) || 0
                        }));
                        setCharts((current) => ({
                            ...current,
                            daily: {
                                labels: hourlyData.map(h => h.hour || ''),
                                sales: hourlyData.map(h => h.gross_sales || 0),
                                volume: hourlyData.map(h => h.transaction_count || 0)
                            }
                        }));
                    })
                    .finally(() => markSectionDone('daily'))],
                ['weekly', axios.get('/commercial/reports/transactions/weekly', { params: { date_from: sevenDaysAgoStr, date_to: todayStr, ...dashboardParams } })
                    .then((weeklyResp) => {
                        const days = weeklyResp.data?.days || [];
                        const weekTotal = days.reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);

                        setMetrics((current) => ({
                            ...current,
                            this_week_total: Number(weekTotal) || 0
                        }));
                        setCharts((current) => ({
                            ...current,
                            weekly: {
                                labels: days.map(d => d.date || ''),
                                sales: days.map(d => d.gross_sales || 0),
                                volume: days.map(d => d.transaction_count || 0)
                            }
                        }));
                    })
                    .finally(() => markSectionDone('weekly'))],
                ['monthly', axios.get('/commercial/reports/transactions/monthly', { params: { date_from: monthStartStr, date_to: todayStr, ...dashboardParams } })
                    .then((monthlyResp) => {
                        const days = monthlyResp.data?.days || [];
                        const monthTotal = days.reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);

                        setMetrics((current) => ({
                            ...current,
                            this_month_total: Number(monthTotal) || 0,
                            this_year_total: Number(monthTotal) || 0
                        }));
                        setCharts((current) => ({
                            ...current,
                            monthly: {
                                labels: days.map(d => d.date || ''),
                                sales: days.map(d => d.gross_sales || 0),
                                volume: days.map(d => d.transaction_count || 0)
                            }
                        }));
                    })
                    .finally(() => markSectionDone('monthly'))],
                ['transactions', api.getTransactions(1, { per_page: 10 })
                    .then((transactionsRes) => {
                        setRecentTransactions(transactionsRes.data?.data || transactionsRes.data || []);
                    })
                    .finally(() => markSectionDone('transactions'))]
            ];

            const settled = await Promise.allSettled(requests.map(([, request]) => request));
            const failedSections = requests
                .filter((_, index) => settled[index].status === 'rejected')
                .map(([key]) => key);

            if (failedSections.length > 0) {
                console.error('Commercial dashboard sections failed:', failedSections, settled);
                setError(`Some dashboard sections could not sync: ${failedSections.join(', ')}.`);
                setNotification({ message: 'Some commercial dashboard sections failed to synchronize.', type: 'error' });
            }

        } catch (err) {
            console.error('Error fetching commercial dashboard data:', err);
            setError('Failed to sync ecosystem vitals. The data shown might be outdated.');
            setNotification({ message: 'Critical: Failed to synchronize with TSMS core.', type: 'error' });
        } finally {
            setRefreshing(false);
        }
    }, [setError]);

    useEffect(() => {
        fetchData(true);
    }, [fetchData]);

    useEffect(() => {
        if (refreshInterval <= 0) return;
        const timer = setInterval(() => fetchData(false), refreshInterval);
        return () => clearInterval(timer);
    }, [fetchData, refreshInterval]);

    const formatCurrency = (val) => {
        const num = Number(val);
        if (isNaN(num)) return '₱0.00';
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    return (
        <div className="p-8 max-w-[1600px] mx-auto space-y-12 animate-in fade-in slide-in-from-bottom-4 duration-700">
            {/* Page Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <h2 className="text-4xl font-black text-slate-900 tracking-tight leading-none mb-3">Commercial Command</h2>
                    <p className="text-slate-500 font-medium">Real-time commercial sales performance and ecosystem vitals.</p>
                </div>
                <div className="flex items-center gap-3">
                    <button
                        onClick={() => fetchData(false)}
                        disabled={refreshing}
                        className="flex items-center gap-2 bg-gradient-to-br from-blue-600 to-blue-800 text-white px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-blue-900/20 transition-all hover:opacity-90 active:scale-95 disabled:opacity-50"
                    >
                        {refreshing ? <CircularProgress size={16} color="inherit" /> : <SyncIcon sx={{ fontSize: 18 }} />}
                        {refreshing ? 'Syncing Ecosystem...' : 'Force Sync'}
                    </button>
                </div>
            </div>

            {error && (
                <Alert severity="error" variant="filled" sx={{ borderRadius: 3, boxShadow: '0 4px 12px rgba(211, 47, 47, 0.2)' }}>
                    {error}
                </Alert>
            )}

            {/* Metrics Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <MetricCard
                    title="Today's Performance"
                    value={formatCurrency(metrics.today_gross)}
                    icon={<TodayIcon />}
                    subtitle="Live Gross Sales"
                />
                <MetricCard
                    title="Active Week"
                    value={formatCurrency(metrics.this_week_total)}
                    icon={<DateRangeIcon />}
                    subtitle="Weekly Velocity"
                />
                <MetricCard
                    title="Current Month"
                    value={formatCurrency(metrics.this_month_total)}
                    icon={<CalendarMonthIcon />}
                    subtitle="Target Tracking"
                />
                <MetricCard
                    title="Annual Aggregate"
                    value={formatCurrency(metrics.this_year_total)}
                    icon={<HistoryIcon />}
                    subtitle="Year-to-Date"
                />
            </div>

            {/* Charts Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div className="glass-card p-8 rounded-[32px] border border-white/40 shadow-xl overflow-hidden relative">
                    <div className="flex items-center justify-between mb-8 relative z-10">
                        <div>
                            <h4 className="text-xl font-black text-slate-900 tracking-tight">Daily Performance</h4>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">24h sales stream</p>
                        </div>
                        <QueryStatsIcon sx={{ color: 'grey.300', fontSize: 32 }} />
                    </div>
                    <div className="h-[350px] relative z-10">
                        <TransactionChart data={charts.daily} loading={sectionLoading.daily} />
                    </div>
                    <div className="absolute -bottom-10 -right-10 opacity-5 grayscale pointer-events-none">
                        <QueryStatsIcon sx={{ fontSize: 240 }} />
                    </div>
                </div>

                <div className="glass-card p-8 rounded-[32px] border border-white/40 shadow-xl overflow-hidden relative">
                    <div className="flex items-center justify-between mb-8 relative z-10">
                        <div>
                            <h4 className="text-xl font-black text-slate-900 tracking-tight">Weekly Lifecycle</h4>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">7-day traffic trends</p>
                        </div>
                        <TimelineIcon sx={{ color: 'grey.300', fontSize: 32 }} />
                    </div>
                    <div className="h-[350px] relative z-10">
                        <TransactionChart data={charts.weekly} loading={sectionLoading.weekly} />
                    </div>
                    <div className="absolute -bottom-10 -right-10 opacity-5 grayscale pointer-events-none">
                        <TimelineIcon sx={{ fontSize: 240 }} />
                    </div>
                </div>

                <div className="glass-card p-8 rounded-[32px] border border-white/40 shadow-xl lg:col-span-2 overflow-hidden relative group">
                    <div className="flex items-center justify-between mb-8 relative z-10">
                        <div>
                            <h4 className="text-xl font-black text-slate-900 tracking-tight">Strategic Growth</h4>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">Long-term monthly trajectory</p>
                        </div>
                        <div className="flex gap-2">
                            <div className="px-3 py-1 bg-emerald-50 rounded-lg text-[10px] font-black text-emerald-600 uppercase tracking-widest flex items-center gap-1.5">
                                <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Positive Trend
                            </div>
                        </div>
                    </div>
                    <div className="h-[400px] relative z-10">
                        <TransactionChart data={charts.monthly} loading={sectionLoading.monthly} />
                    </div>
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-[0.02] pointer-events-none group-hover:opacity-[0.05] transition-opacity">
                        <AnalyticsIcon sx={{ fontSize: 500 }} />
                    </div>
                </div>
            </div>

            {/* Section: Actionable Data Tables */}
            <div className="mt-12 space-y-6">
                <div className="flex items-center gap-3">
                    <div className="size-10 rounded-xl bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center text-white shadow-lg shadow-blue-900/20">
                        <ListAltIcon />
                    </div>
                    <h4 className="text-xl font-black text-slate-900 tracking-tight">Recent Live Transactions</h4>
                </div>
                <div className="glass-card rounded-[32px] border border-white/40 shadow-xl overflow-hidden bg-white/50 backdrop-blur-3xl">
                    <RecentTransactionsTable
                        transactions={recentTransactions}
                        loading={sectionLoading.transactions}
                    />
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

export default CommercialDashboardPage;
