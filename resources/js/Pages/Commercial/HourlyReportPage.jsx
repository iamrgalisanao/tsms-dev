import React, { useState, useEffect } from 'react';
import axios from 'axios';
import MainLayout from '@/Layouts/MainLayout';
import ReportHeader from '@/Components/Commercial/ReportHeader';
import MetricCard from '@/Components/Commercial/MetricCard';
import { Card, Table, Button, Form, Row, Col, Spinner } from 'react-bootstrap';
import moment from 'moment';

const HourlyReportPage = () => {
    const [date, setDate] = useState(moment().format('YYYY-MM-DD'));
    const [tenants, setTenants] = useState([]);
    const [selectedTenant, setSelectedTenant] = useState('');
    const [loading, setLoading] = useState(false);
    const [reportData, setReportData] = useState([]);
    const [totals, setTotals] = useState(null);
    const [averages, setAverages] = useState(null);

    useEffect(() => {
        fetchTenants();
    }, []);

    const fetchTenants = async () => {
        try {
            const response = await axios.get('/commercial/reports/tenants');
            setTenants(response.data || []);
        } catch (error) {
            console.error('Failed to fetch tenants:', error);
        }
    };

    const loadReport = async () => {
        if (!date || !selectedTenant) {
            alert('Please select both date and tenant');
            return;
        }

        setLoading(true);
        try {
            const response = await axios.get('/commercial/reports/sales-report/transactions/hourly', {
                params: { date, tenant_id: selectedTenant }
            });
            const data = response.data.data || [];
            setReportData(data);
            calculateMetrics(data);
        } catch (error) {
            console.error('Failed to load hourly report:', error);
            setReportData([]);
        } finally {
            setLoading(false);
        }
    };

    const calculateMetrics = (data) => {
        if (!data || data.length === 0) {
            setTotals(null);
            setAverages(null);
            return;
        }

        const keys = [
            'gross_sales', 'vatable_sales', 'vat_exempt_sales', 'vat_amount',
            'sc_pwd_discount', 'regular_discount', 'void', 'return', 'net_sales',
            'cash_payment', 'card_payment', 'other_tender', 'net_sales_percentage_rent',
            'transaction_count', 'guest_count'
        ];

        const sums = {};
        keys.forEach(key => {
            sums[key] = data.reduce((acc, row) => acc + (Number(row[key]) || 0), 0);
        });

        const avgs = {};
        keys.forEach(key => {
            avgs[key] = sums[key] / 24; // Average across 24 hours
        });

        setTotals(sums);
        setAverages(avgs);
    };

    const handleExport = () => {
        const url = `/commercial/reports/sales-report/export?date=${date}&tenant_id=${selectedTenant}`;
        window.location.href = url;
    };

    const formatCurrency = (val) => {
        const num = Number(val);
        return isNaN(num) ? '0.00' : num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const renderTableBody = () => {
        if (loading) {
            return (
                <tr>
                    <td colSpan="21" className="text-center py-5">
                        <Spinner animation="border" variant="primary" size="sm" className="me-2" />
                        Loading report data...
                    </td>
                </tr>
            );
        }

        if (reportData.length === 0) {
            return (
                <tr>
                    <td colSpan="21" className="text-center py-5 text-muted">
                        <i className="material-symbols-outlined align-middle me-2">info</i>
                        No data available. Select parameters and click "Load Report".
                    </td>
                </tr>
            );
        }

        // Build 24 hours
        return Array.from({ length: 24 }).map((_, i) => {
            const hourStr = `${String(i).padStart(2, '0')}:00`;
            const row = reportData.find(r => r.hour === hourStr) || {};

            return (
                <tr key={hourStr}>
                    <td className="text-center">{row.customer_code || '-'}</td>
                    <td>{row.tenant_name || '-'}</td>
                    <td className="text-center">{row.location || '-'}</td>
                    <td className="text-center">{row.zone || '-'}</td>
                    <td className="text-center">{row.sales_date || '-'}</td>
                    <td className="text-center font-weight-bold bg-light">{hourStr}</td>
                    <td className="text-right">{formatCurrency(row.gross_sales)}</td>
                    <td className="text-right">{formatCurrency(row.vatable_sales)}</td>
                    <td className="text-right">{formatCurrency(row.vat_exempt_sales)}</td>
                    <td className="text-right">{formatCurrency(row.vat_amount)}</td>
                    <td className="text-right">{formatCurrency(row.sc_pwd_discount)}</td>
                    <td className="text-right">{formatCurrency(row.regular_discount)}</td>
                    <td className="text-right">{formatCurrency(row.void)}</td>
                    <td className="text-right">{formatCurrency(row.return)}</td>
                    <td className="text-right font-weight-bold">{formatCurrency(row.net_sales)}</td>
                    <td className="text-right">{formatCurrency(row.cash_payment)}</td>
                    <td className="text-right">{formatCurrency(row.card_payment)}</td>
                    <td className="text-right">{formatCurrency(row.other_tender)}</td>
                    <td className="text-right">{formatCurrency(row.net_sales_percentage_rent)}</td>
                    <td className="text-center">{row.transaction_count || 0}</td>
                    <td className="text-center">{row.guest_count || 0}</td>
                </tr>
            );
        });
    };

    const renderTotalRow = (label, data, isAvg = false) => {
        if (!data) return null;
        return (
            <tr className={isAvg ? "table-info font-weight-bold" : "table-warning font-weight-bold"}>
                <td colSpan="6" className="text-center">{label}</td>
                <td className="text-right">{formatCurrency(data.gross_sales)}</td>
                <td className="text-right">{formatCurrency(data.vatable_sales)}</td>
                <td className="text-right">{formatCurrency(data.vat_exempt_sales)}</td>
                <td className="text-right">{formatCurrency(data.vat_amount)}</td>
                <td className="text-right">{formatCurrency(data.sc_pwd_discount)}</td>
                <td className="text-right">{formatCurrency(data.regular_discount)}</td>
                <td className="text-right">{formatCurrency(data.void)}</td>
                <td className="text-right">{formatCurrency(data.return)}</td>
                <td className="text-right">{formatCurrency(data.net_sales)}</td>
                <td className="text-right">{formatCurrency(data.cash_payment)}</td>
                <td className="text-right">{formatCurrency(data.card_payment)}</td>
                <td className="text-right">{formatCurrency(data.other_tender)}</td>
                <td className="text-right">{formatCurrency(data.net_sales_percentage_rent)}</td>
                <td className="text-center">{isAvg ? data.transaction_count.toFixed(2) : data.transaction_count}</td>
                <td className="text-center">{isAvg ? data.guest_count.toFixed(2) : data.guest_count}</td>
            </tr>
        );
    };

    return (
        <div className="p-8 max-w-[1600px] mx-auto space-y-8">
            <ReportHeader
                title="Hourly Velocity Report"
                dateFrom={date}
                dateTo={date}
                tenantId={selectedTenant}
                tenants={tenants}
                onDateFromChange={setDate}
                onDateToChange={setDate}
                onTenantChange={setSelectedTenant}
                onLoadReport={loadReport}
                onExportExcel={handleExport}
                loading={loading}
            />

            {/* Metrics Row (Small Summary) */}
            {totals && (
                <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    <div className="glass-card rounded-2xl p-4 border border-white/40">
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Gross</p>
                        <p className="text-xl font-black text-slate-900">₱{formatCurrency(totals.gross_sales)}</p>
                    </div>
                    <div className="glass-card rounded-2xl p-4 border border-white/40">
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Avg Hourly</p>
                        <p className="text-xl font-black text-slate-900">₱{formatCurrency(averages.gross_sales)}</p>
                    </div>
                    <div className="glass-card rounded-2xl p-4 border border-white/40">
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Total TX</p>
                        <p className="text-xl font-black text-slate-900">{totals.transaction_count}</p>
                    </div>
                    <div className="glass-card rounded-2xl p-4 border border-white/40">
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Net Sales</p>
                        <p className="text-xl font-black text-slate-900">₱{formatCurrency(totals.net_sales)}</p>
                    </div>
                    <div className="glass-card rounded-2xl p-4 border border-white/40 bg-slate-900 text-white">
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Peak Hour</p>
                        <p className="text-xl font-black text-primary italic">12:00</p>
                    </div>
                </div>
            )}

            <div className="glass-card rounded-3xl overflow-hidden border border-white/40 shadow-xl">
                <div className="p-8 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 className="text-xl font-black text-slate-900 tracking-tight">Granular Hourly Ledger</h3>
                        <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">24-hour sales distribution</p>
                    </div>
                </div>

                <div className="overflow-auto max-h-[700px] custom-scrollbar">
                    <table className="w-full text-left border-collapse">
                        <thead className="bg-slate-50/80 backdrop-blur-md sticky top-0 z-20">
                            <tr className="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-200">
                                <th className="px-6 py-4 text-center sticky left-0 z-30 bg-slate-50/80">Hour</th>
                                <th className="px-6 py-4 text-right bg-rose-50/30 text-rose-700">Gross Sales</th>
                                <th className="px-6 py-4 text-right text-slate-500">Vatable</th>
                                <th className="px-6 py-4 text-right text-slate-500">VAT</th>
                                <th className="px-6 py-4 text-right text-slate-500">SC/PWD</th>
                                <th className="px-6 py-4 text-right font-black text-slate-900 bg-rose-50/50">Net Sales</th>
                                <th className="px-6 py-4 text-right text-emerald-600 bg-emerald-50/30">Cash</th>
                                <th className="px-6 py-4 text-right text-blue-600 bg-blue-50/30">Card</th>
                                <th className="px-6 py-4 text-center">Transactions</th>
                                <th className="px-6 py-4 text-center">Guests</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {Array.from({ length: 24 }).map((_, i) => {
                                const hourStr = `${String(i).padStart(2, '0')}:00`;
                                const row = reportData.find(r => r.hour === hourStr) || {};
                                return (
                                    <tr key={hourStr} className="hover:bg-slate-50/50 transition-colors group">
                                        <td className="px-6 py-3 text-center font-black text-slate-900 text-xs bg-slate-50/30 sticky left-0 z-10">
                                            {hourStr}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-black text-rose-600">
                                            ₱{formatCurrency(row.gross_sales)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-bold text-slate-500">
                                            ₱{formatCurrency(row.vatable_sales)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-bold text-slate-500">
                                            ₱{formatCurrency(row.vat_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-bold text-slate-500">
                                            ₱{formatCurrency(row.sc_pwd_discount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-black text-slate-900 bg-rose-50/20">
                                            ₱{formatCurrency(row.net_sales)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-bold text-emerald-600 bg-emerald-50/10">
                                            ₱{formatCurrency(row.cash_payment)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-xs font-bold text-blue-600 bg-blue-50/10">
                                            ₱{formatCurrency(row.card_payment)}
                                        </td>
                                        <td className="px-6 py-3 text-center">
                                            <span className="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-600">
                                                {row.transaction_count || 0}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-center">
                                            <span className="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-600">
                                                {row.guest_count || 0}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        {totals && (
                            <tfoot className="bg-slate-100/80 backdrop-blur-md sticky bottom-0 z-20">
                                <tr className="text-xs font-black text-slate-900 border-t-2 border-slate-200">
                                    <td className="px-6 py-4 text-center sticky left-0 z-30 bg-slate-100/80 uppercase tracking-widest">Totals</td>
                                    <td className="px-6 py-4 text-right">₱{formatCurrency(totals.gross_sales)}</td>
                                    <td className="px-6 py-4 text-right">₱{formatCurrency(totals.vatable_sales)}</td>
                                    <td className="px-6 py-4 text-right">₱{formatCurrency(totals.vat_amount)}</td>
                                    <td className="px-6 py-4 text-right">₱{formatCurrency(totals.sc_pwd_discount)}</td>
                                    <td className="px-6 py-4 text-right bg-rose-50/50">₱{formatCurrency(totals.net_sales)}</td>
                                    <td className="px-6 py-4 text-right bg-emerald-50/30">₱{formatCurrency(totals.cash_payment)}</td>
                                    <td className="px-6 py-4 text-right bg-blue-50/30">₱{formatCurrency(totals.card_payment)}</td>
                                    <td className="px-6 py-4 text-center">{totals.transaction_count}</td>
                                    <td className="px-6 py-4 text-center">{totals.guest_count}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                {!reportData.length && !loading && (
                    <div className="py-20 flex flex-col items-center justify-center opacity-30 gap-2">
                        <span className="material-symbols-outlined text-6xl">data_exploration</span>
                        <p className="text-xs font-black uppercase tracking-widest text-slate-500">Awaiting Search Parameters</p>
                    </div>
                )}
            </div>
        </div>
    );
};

export default HourlyReportPage;
