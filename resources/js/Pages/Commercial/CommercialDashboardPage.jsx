import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { format, subDays, startOfMonth, startOfYear } from 'date-fns';
import MetricCard from '../../Components/Commercial/MetricCard';
import TransactionChart from '../../Components/dashboard/TransactionChart';

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
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);

    const fetchData = useCallback(async () => {
        setRefreshing(true);
        try {
            // Define date parameters for the endpoints
            const todayStr = format(new Date(), 'yyyy-MM-dd');
            const sevenDaysAgoStr = format(subDays(new Date(), 6), 'yyyy-MM-dd'); // 7 days including today
            const monthStartStr = format(startOfMonth(new Date()), 'yyyy-MM-dd');
            const yearStartStr = format(startOfYear(new Date()), 'yyyy-MM-dd');

            // Daily: use hourly endpoint for chart breakdown + daily for summary
            // Weekly/Monthly/Yearly use their respective endpoints
            const [hourlyResp, dailyResp, weeklyResp, monthlyResp] = await Promise.all([
                axios.get('/commercial/reports/transactions/hourly', { params: { date: todayStr } }).catch(() => ({ data: { data: [] } })),
                axios.get('/commercial/reports/transactions/daily', { params: { date: todayStr } }).catch(() => ({ data: { summary: { gross_sales: 0 } } })),
                axios.get('/commercial/reports/transactions/weekly', { params: { date_from: sevenDaysAgoStr, date_to: todayStr } }).catch(() => ({ data: { days: [] } })),
                axios.get('/commercial/reports/transactions/monthly', { params: { date_from: monthStartStr, date_to: todayStr } }).catch(() => ({ data: { days: [] } }))
            ]);

            const todaySum = dailyResp.data?.summary?.gross_sales || 0;
            const weekTotal = (weeklyResp.data?.days || []).reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);
            const monthTotal = (monthlyResp.data?.days || []).reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);

            // For year total, we might need a separate call or aggregate monthly
            // For now let's just use the monthly data total as a starting point if yearly is missing
            const yearTotal = monthTotal; // Placeholder or add yearly call later if needed

            setMetrics({
                today_gross: Number(todaySum) || 0,
                this_week_total: Number(weekTotal) || 0,
                this_month_total: Number(monthTotal) || 0,
                this_year_total: Number(yearTotal) || 0
            });

            // Map hourly data for daily chart
            const hourlyData = hourlyResp.data?.data || [];

            setCharts({
                daily: {
                    labels: (hourlyData || []).map(h => h.hour || ''),
                    sales: (hourlyData || []).map(h => h.gross_sales || 0),
                    volume: (hourlyData || []).map(h => h.transaction_count || 0)
                },
                weekly: {
                    labels: (weeklyResp.data?.days || []).map(d => d.date || ''),
                    sales: (weeklyResp.data?.days || []).map(d => d.gross_sales || 0),
                    volume: (weeklyResp.data?.days || []).map(d => d.transaction_count || 0)
                },
                monthly: {
                    labels: (monthlyResp.data?.days || []).map(d => d.date || ''),
                    sales: (monthlyResp.data?.days || []).map(d => d.gross_sales || 0),
                    volume: (monthlyResp.data?.days || []).map(d => d.transaction_count || 0)
                },
                yearly: { labels: [], sales: [], volume: [] }
            });

        } catch (error) {
            console.error('Error fetching commercial dashboard data:', error);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

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
                        onClick={fetchData}
                        disabled={refreshing}
                        className="flex items-center gap-2 pitx-gradient text-white px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-primary/20 transition-all hover:opacity-90 active:scale-95 disabled:opacity-50"
                    >
                        <span className={`material - symbols - outlined text - sm ${refreshing ? 'animate-spin' : ''} `}>sync</span>
                        {refreshing ? 'Syncing Ecosystem...' : 'Force Sync'}
                    </button>
                </div>
            </div>

            {/* Metrics Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <MetricCard
                    title="Today's Performance"
                    value={formatCurrency(metrics.today_gross)}
                    icon="today"
                    subtitle="Live Gross Sales"
                />
                <MetricCard
                    title="Active Week"
                    value={formatCurrency(metrics.this_week_total)}
                    icon="date_range"
                    subtitle="Weekly Velocity"
                />
                <MetricCard
                    title="Current Month"
                    value={formatCurrency(metrics.this_month_total)}
                    icon="calendar_month"
                    subtitle="Target Tracking"
                />
                <MetricCard
                    title="Annual Aggregate"
                    value={formatCurrency(metrics.this_year_total)}
                    icon="history"
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
                        <span className="material-symbols-outlined text-slate-300">query_stats</span>
                    </div>
                    <div className="h-[350px] relative z-10">
                        <TransactionChart data={charts.daily} loading={loading} />
                    </div>
                    <div className="absolute -bottom-10 -right-10 opacity-5 grayscale pointer-events-none">
                        <span className="material-symbols-outlined text-[200px]">monitoring</span>
                    </div>
                </div>

                <div className="glass-card p-8 rounded-[32px] border border-white/40 shadow-xl overflow-hidden relative">
                    <div className="flex items-center justify-between mb-8 relative z-10">
                        <div>
                            <h4 className="text-xl font-black text-slate-900 tracking-tight">Weekly Lifecycle</h4>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">7-day traffic trends</p>
                        </div>
                        <span className="material-symbols-outlined text-slate-300">timeline</span>
                    </div>
                    <div className="h-[350px] relative z-10">
                        <TransactionChart data={charts.weekly} loading={loading} />
                    </div>
                    <div className="absolute -bottom-10 -right-10 opacity-5 grayscale pointer-events-none">
                        <span className="material-symbols-outlined text-[200px]">hub</span>
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
                        <TransactionChart data={charts.monthly} loading={loading} />
                    </div>
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-[0.02] pointer-events-none group-hover:opacity-[0.05] transition-opacity">
                        <span className="material-symbols-outlined text-[500px]">analytics</span>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default CommercialDashboardPage;
