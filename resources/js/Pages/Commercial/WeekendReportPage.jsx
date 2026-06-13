import React, { useState, useEffect } from 'react';
import axios from 'axios';
import {
    Container,
    Paper,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Typography,
    Box,
    Grid,
    CircularProgress,
    Alert
} from '@mui/material';
import {
    Hotel as WeekendIcon,
    TrendingUp as TrendingIcon,
    Star as PeakIcon,
    ReceiptLong as ReceiptIcon
} from '@mui/icons-material';
import ReportHeader from '../../Components/Commercial/ReportHeader';
import MetricCard from '../../Components/Commercial/MetricCard';

const WeekendReportPage = () => {
    // Default to current weekend Sat-Sun
    const now = new Date();
    const day = now.getDay();
    const diffToSat = day === 0 ? -1 : 6 - day;
    const sat = new Date(now.setDate(now.getDate() + diffToSat));
    const sun = new Date(sat);
    sun.setDate(sat.getDate() + 1);

    const [dateFrom, setDateFrom] = useState(sat.toISOString().split('T')[0]);
    const [dateTo, setDateTo] = useState(sun.toISOString().split('T')[0]);
    const [tenantId, setTenantId] = useState('');
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchTenants = async () => {
            try {
                const response = await axios.get('/commercial/reports/tenants');
                setTenants(response.data);
            } catch (err) {
                console.error('Failed to fetch tenants', err);
            }
        };
        fetchTenants();
        loadReport();
    }, []);

    const loadReport = async () => {
        try {
            setLoading(true);
            setError(null);
            const response = await axios.get('/commercial/reports/sales-report/transactions/weekend', {
                params: { date_from: dateFrom, date_to: dateTo, tenant_id: tenantId }
            });
            setData(response.data);
        } catch (err) {
            setError('Failed to load report data. Please try again.');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleExport = () => {
        const url = `/commercial/reports/sales-report/export?date=${dateFrom}&tenant_id=${tenantId || 'all'}`;
        window.open(url, '_blank');
    };

    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val || 0);

    const summary = data?.summary || {};
    const reportData = data?.days || [];

    return (
        <div className="p-8 max-w-[1600px] mx-auto space-y-8">
            <ReportHeader
                title="Weekend Peak Analytics"
                dateFrom={dateFrom}
                dateTo={dateTo}
                tenantId={tenantId}
                tenants={tenants}
                onDateFromChange={setDateFrom}
                onDateToChange={setDateTo}
                onTenantChange={setTenantId}
                onLoadReport={loadReport}
                onExportExcel={handleExport}
                loading={loading}
            />

            {/* Metrics Row */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <MetricCard
                    title="Weekend Gross Sales"
                    value={formatCurrency(summary.gross_sales)}
                    icon="hotel"
                    subtitle="Sat-Sun Aggregate"
                />
                <MetricCard
                    title="Sunday Peak"
                    value={formatCurrency(reportData.find(d => d.date?.includes('Sunday'))?.gross_sales || 0)}
                    icon="star"
                    subtitle="Primary Weekend Driver"
                />
                <MetricCard
                    title="Transaction Volume"
                    value={summary.transaction_count?.toLocaleString() || '0'}
                    icon="receipt_long"
                    subtitle="Total Period TX"
                />
            </div>

            <div className="glass-card rounded-3xl overflow-hidden border border-white/40 shadow-xl">
                <div className="p-8 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 className="text-xl font-black text-slate-900 tracking-tight">Peak Period Breakdown</h3>
                        <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">Saturday vs Sunday performance</p>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead className="bg-slate-50/50">
                            <tr className="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th className="px-8 py-4">Business Date</th>
                                <th className="px-8 py-4 text-right">Gross Revenue</th>
                                <th className="px-8 py-4 text-right">Net Revenue</th>
                                <th className="px-8 py-4 text-center">Transactions</th>
                                <th className="px-8 py-4 text-center">Guests</th>
                                <th className="px-8 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {reportData.map((row, idx) => (
                                <tr key={idx} className="hover:bg-slate-50/80 transition-colors group">
                                    <td className="px-8 py-4 font-black text-slate-900 text-sm">
                                        {row.date}
                                    </td>
                                    <td className="px-8 py-4 text-right text-sm font-black text-slate-900">
                                        {formatCurrency(row.gross_sales)}
                                    </td>
                                    <td className="px-8 py-4 text-right text-sm font-bold text-slate-500">
                                        {formatCurrency(row.net_sales)}
                                    </td>
                                    <td className="px-8 py-4 text-center">
                                        <span className="px-3 py-1 bg-slate-100 rounded-lg text-xs font-black text-slate-600">
                                            {row.transaction_count?.toLocaleString()}
                                        </span>
                                    </td>
                                    <td className="px-8 py-4 text-center">
                                        <span className="px-3 py-1 bg-slate-100 rounded-lg text-xs font-black text-slate-600">
                                            {row.guest_count?.toLocaleString()}
                                        </span>
                                    </td>
                                    <td className="px-8 py-4 text-center">
                                        <div className="flex items-center justify-center gap-1.5 px-3 py-1 bg-emerald-50 rounded-lg text-[10px] font-black text-emerald-600 uppercase tracking-widest">
                                            <span className="size-1.5 rounded-full bg-emerald-500"></span>
                                            Verified
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {reportData.length === 0 && !loading && (
                                <tr>
                                    <td colSpan="6" className="px-8 py-20 text-center">
                                        <div className="flex flex-col items-center gap-2 opacity-30">
                                            <span className="material-symbols-outlined text-6xl">nightlife</span>
                                            <p className="text-xs font-black uppercase tracking-widest">No weekend data segments found</p>
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

export default WeekendReportPage;
