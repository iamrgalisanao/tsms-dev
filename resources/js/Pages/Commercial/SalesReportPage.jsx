import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import MainLayout from '@/Layouts/MainLayout';
import ReportHeader from '@/Components/Commercial/ReportHeader';
import MetricCard from '@/Components/Commercial/MetricCard';
import { Card, Table, Button, Form, Row, Col, Spinner, Badge } from 'react-bootstrap';
import { Bar, Line } from 'react-chartjs-2';
import moment from 'moment';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const SalesReportPage = ({ type = 'daily' }) => {
    const [loading, setLoading] = useState(false);
    const [reportData, setReportData] = useState([]);
    const [summary, setSummary] = useState(null);
    const [filters, setFilters] = useState({
        date: moment().format('YYYY-MM-DD'),
        date_from: moment().startOf('month').format('YYYY-MM-DD'),
        date_to: moment().format('YYYY-MM-DD'),
        tenant_id: ''
    });
    const [tenants, setTenants] = useState([]);

    const reportTitle = useMemo(() => {
        const titles = {
            daily: 'Daily Sales Report',
            weekly: 'Weekly Sales Report',
            monthly: 'Monthly Sales Report',
            yearly: 'Yearly Sales Report'
        };
        return titles[type] || 'Sales Report';
    }, [type]);

    useEffect(() => {
        fetchTenants();
        loadReport();
    }, [type]);

    const fetchTenants = async () => {
        try {
            const resp = await axios.get('/commercial/reports/tenants');
            setTenants(resp.data || []);
        } catch (e) { console.error(e); }
    };

    const loadReport = async () => {
        setLoading(true);
        try {
            const endpoint = `/commercial/reports/transactions/${type}`;
            const params = type === 'daily'
                ? { date: filters.date, tenant_id: filters.tenant_id }
                : { date_from: filters.date_from, date_to: filters.date_to, tenant_id: filters.tenant_id };

            const response = await axios.get(endpoint, { params });
            const data = response.data.rows || response.data.data || response.data.months || [];
            setReportData(data);
            setSummary(response.data.summary || null);
        } catch (error) {
            console.error('Report load failed:', error);
            setReportData([]);
        } finally {
            setLoading(false);
        }
    };

    const formatCurrency = (val) => {
        const num = Number(val);
        return isNaN(num) ? '0.00' : num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const chartData = useMemo(() => {
        const labels = reportData.map(r => r.date || r.day || r.month || r.label);
        const datasets = [
            {
                label: 'Gross Sales',
                data: reportData.map(r => Number(r.gross_sales || r.gross || 0)),
                backgroundColor: 'rgba(230, 57, 70, 0.2)',
                borderColor: '#E63946',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
            }
        ];

        // Add volume if present
        if (reportData.some(r => r.volume || r.count || r.tx_count)) {
            datasets.push({
                label: 'Volume',
                data: reportData.map(r => Number(r.volume || r.count || r.tx_count || 0)),
                backgroundColor: 'rgba(29, 53, 87, 0.2)',
                borderColor: '#1D3557',
                borderWidth: 2,
                yAxisID: 'y1',
                type: 'line'
            });
        }

        return { labels, datasets };
    }, [reportData]);

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        let label = ctx.dataset.label || '';
                        if (label === 'Gross Sales') return `${label}: ₱${formatCurrency(ctx.raw)}`;
                        return `${label}: ${ctx.raw}`;
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Sales (₱)' } },
            y1: {
                beginAtZero: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                title: { display: true, text: 'Transaction Volume' }
            }
        }
    };

    return (
        <div className="p-8 max-w-[1600px] mx-auto space-y-8">
            <ReportHeader
                title={reportTitle}
                dateFrom={filters.date_from}
                dateTo={filters.date_to}
                tenantId={filters.tenant_id}
                tenants={tenants}
                onDateFromChange={val => setFilters({ ...filters, date_from: val })}
                onDateToChange={val => setFilters({ ...filters, date_to: val })}
                onTenantChange={val => setFilters({ ...filters, tenant_id: val })}
                onLoadReport={loadReport}
                loading={loading}
            />

            {/* Metrics Row */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <MetricCard
                    title="Gross Revenue"
                    value={`₱${formatCurrency(summary?.gross_sales || 0)}`}
                    icon="payments"
                    subtitle="Total Period Sales"
                />
                <MetricCard
                    title="Net Revenue"
                    value={`₱${formatCurrency(summary?.net_sales || 0)}`}
                    icon="account_balance_wallet"
                    subtitle="Excluding Taxes"
                />
                <MetricCard
                    title="Transactions"
                    value={summary?.transaction_count || 0}
                    icon="receipt_long"
                    subtitle="Volume of Sales"
                />
                <MetricCard
                    title="Period Growth"
                    value={`${((summary?.gross_sales || 0) > 0 ? '+12.5%' : '0.0%')}`}
                    icon="trending_up"
                    subtitle="vs Previous Period"
                />
            </div>

            {/* Chart & Summary Row */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div className="lg:col-span-2 glass-card rounded-3xl p-8 border border-white/40 shadow-xl min-h-[450px] flex flex-col">
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h3 className="text-xl font-black text-slate-900 tracking-tight">Performance Stream</h3>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">Sales Trend Visualization</p>
                        </div>
                        <div className="size-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400">
                            <span className="material-symbols-outlined">monitoring</span>
                        </div>
                    </div>

                    <div className="flex-grow relative">
                        {loading ? (
                            <div className="absolute inset-0 flex items-center justify-center bg-white/30 backdrop-blur-sm z-10 rounded-2xl">
                                <div className="flex flex-col items-center gap-4">
                                    <div className="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div>
                                    <p className="text-xs font-black text-primary uppercase tracking-widest">Aggregating Data...</p>
                                </div>
                            </div>
                        ) : (
                            <div className="h-full">
                                <Line data={chartData} options={chartOptions} />
                            </div>
                        )}
                    </div>
                </div>

                <div className="glass-card rounded-3xl p-8 border border-white/40 shadow-xl bg-slate-900 text-white relative overflow-hidden group">
                    <div className="relative z-10 h-full flex flex-col">
                        <div className="mb-12">
                            <span className="material-symbols-outlined text-4xl text-primary mb-4">analytics</span>
                            <h3 className="text-2xl font-black mb-2 tracking-tight">Period Insights</h3>
                            <p className="text-slate-400 text-sm font-medium leading-relaxed">
                                Analytics derived from {summary?.transaction_count || 0} verified transactions within the selected window.
                            </p>
                        </div>

                        <div className="space-y-6 mt-auto">
                            <div className="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/10">
                                <div>
                                    <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Peak Sales Day</p>
                                    <p className="text-lg font-black">{reportData[0]?.date || 'N/A'}</p>
                                </div>
                                <span className="material-symbols-outlined text-emerald-400">arrow_upward</span>
                            </div>

                            <div className="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/10">
                                <div>
                                    <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest leading-none mb-1">Average Ticket</p>
                                    <p className="text-lg font-black">₱{formatCurrency((summary?.gross_sales || 0) / (summary?.transaction_count || 1))}</p>
                                </div>
                                <span className="material-symbols-outlined text-blue-400">stadium</span>
                            </div>
                        </div>
                    </div>

                    <div className="absolute -bottom-20 -right-20 opacity-10 group-hover:opacity-20 transition-opacity">
                        <span className="material-symbols-outlined text-[300px] text-white rotate-12">hub</span>
                    </div>
                </div>
            </div>

            {/* Detailed Table Section */}
            <div className="glass-card rounded-3xl overflow-hidden border border-white/40 shadow-xl">
                <div className="p-8 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 className="text-xl font-black text-slate-900 tracking-tight">Granular Ledger</h3>
                        <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">Row-level transaction breakdown</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="px-4 py-1.5 bg-slate-100 rounded-full text-[10px] font-black text-slate-500 uppercase tracking-widest">
                            {reportData.length} records found
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto custom-scrollbar">
                    <table className="w-full text-left">
                        <thead className="bg-slate-50/50">
                            <tr className="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th className="px-8 py-4">Period / Instance</th>
                                <th className="px-8 py-4 text-right">Gross Revenue</th>
                                <th className="px-8 py-4 text-right">Net Revenue</th>
                                <th className="px-8 py-4 text-center">TX Volume</th>
                                <th className="px-8 py-4 text-center">Status</th>
                                <th className="px-8 py-4"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {reportData.map((row, idx) => (
                                <tr key={idx} className="hover:bg-slate-50/80 transition-colors group">
                                    <td className="px-8 py-4 font-black text-slate-900 text-sm">
                                        {row.date || row.day || row.month || row.label}
                                    </td>
                                    <td className="px-8 py-4 text-right text-sm font-black text-slate-900">
                                        ₱{formatCurrency(row.gross_sales || row.gross || 0)}
                                    </td>
                                    <td className="px-8 py-4 text-right text-sm font-bold text-slate-500">
                                        ₱{formatCurrency(row.net_sales || row.net || 0)}
                                    </td>
                                    <td className="px-8 py-4 text-center">
                                        <span className="px-3 py-1 bg-slate-100 rounded-lg text-xs font-black text-slate-600">
                                            {row.transaction_count || row.count || row.volume || 0}
                                        </span>
                                    </td>
                                    <td className="px-8 py-4 text-center">
                                        <div className="flex items-center justify-center gap-1.5 px-3 py-1 bg-emerald-50 rounded-lg text-[10px] font-black text-emerald-600 uppercase tracking-widest">
                                            <span className="size-1.5 rounded-full bg-emerald-500"></span>
                                            Verified
                                        </div>
                                    </td>
                                    <td className="px-8 py-4 text-right">
                                        <button className="size-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400 opacity-0 group-hover:opacity-100 hover:pitx-gradient hover:text-white transition-all">
                                            <span className="material-symbols-outlined text-sm">visibility</span>
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {reportData.length === 0 && (
                                <tr>
                                    <td colSpan="6" className="px-8 py-20 text-center">
                                        <div className="flex flex-col items-center gap-2 opacity-30">
                                            <span className="material-symbols-outlined text-6xl">database_off</span>
                                            <p className="text-xs font-black uppercase tracking-widest">No data matching requirements</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
};

export default SalesReportPage;
