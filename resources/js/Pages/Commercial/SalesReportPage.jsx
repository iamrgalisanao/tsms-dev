import React, { useState, useEffect, useCallback, useMemo } from 'react';
import axios from 'axios';
import {
    Box,
    Typography,
    Stack,
    Button,
    CircularProgress,
    TextField,
    Autocomplete,
    MenuItem,
    Alert
} from '@mui/material';
import DownloadIcon from '@mui/icons-material/Download';
import SyncIcon from '@mui/icons-material/Sync';
import FilterAltIcon from '@mui/icons-material/FilterAlt';
import BarChartIcon from '@mui/icons-material/BarChart';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import AccountBalanceWalletIcon from '@mui/icons-material/AccountBalanceWallet';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale, LinearScale, PointElement, LineElement,
    Title, Tooltip, Legend, Filler
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

// ─── Helpers ─────────────────────────────────────────────────────────────────
const fmt = (v) => Number(v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const MetricTile = ({ icon, label, value, sub }) => (
    <Box sx={{ bgcolor: 'white', borderRadius: '16px', border: '1px solid', borderColor: 'divider', p: 2.5, flex: 1, minWidth: 160 }}>
        <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mb: 1 }}>
            <Box sx={{ p: 1, bgcolor: 'secondary.main', color: 'white', borderRadius: 2, display: 'flex' }}>{icon}</Box>
            <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'text.secondary' }}>{label}</Typography>
        </Stack>
        <Typography variant="h6" sx={{ fontWeight: 900 }}>{value}</Typography>
        {sub && <Typography variant="caption" sx={{ color: 'text.disabled' }}>{sub}</Typography>}
    </Box>
);

// ─── Report type config ───────────────────────────────────────────────────────
const TYPE_CONFIG = {
    daily: { title: 'Daily Sales Report', endpoint: '/commercial/reports/transactions/daily', icon: 'today', dateMode: 'single' },
    weekly: { title: 'Weekly Sales Report', endpoint: '/commercial/reports/transactions/weekly', icon: 'date_range', dateMode: 'range' },
    monthly: { title: 'Monthly Sales Report', endpoint: '/commercial/reports/transactions/monthly', icon: 'calendar_month', dateMode: 'month' },
    yearly: { title: 'Yearly Sales Report', endpoint: '/commercial/reports/transactions/yearly', icon: 'calendar_today', dateMode: 'year' },
};

const today = () => new Date().toISOString().split('T')[0];
const thisMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

// ─── Main Page ────────────────────────────────────────────────────────────────
const SalesReportPage = ({ type = 'daily' }) => {
    const config = TYPE_CONFIG[type] || TYPE_CONFIG.daily;

    const [tenants, setTenants] = useState([]);
    const [tenantsLoaded, setTenantsLoaded] = useState(false);
    const [selectedTenant, setSelectedTenant] = useState(null);
    const [dateFrom, setDateFrom] = useState(today());
    const [dateTo, setDateTo] = useState(today());
    const [month, setMonth] = useState(thisMonth());
    const [year, setYear] = useState(String(new Date().getFullYear()));
    const [loading, setLoading] = useState(false);
    const [reportData, setReportData] = useState([]);
    const [summary, setSummary] = useState(null);
    const [error, setError] = useState(null);

    const loadTenants = useCallback(async () => {
        if (tenantsLoaded) return;
        try {
            const r = await axios.get('/commercial/reports/tenants');
            setTenants(r.data || []);
            setTenantsLoaded(true);
        } catch { setTenants([]); }
    }, [tenantsLoaded]);

    const buildParams = () => {
        const base = { tenant_id: selectedTenant?.id || '' };
        if (config.dateMode === 'single') return { ...base, date: dateFrom };
        if (config.dateMode === 'range') return { ...base, date_from: dateFrom, date_to: dateTo };
        if (config.dateMode === 'month') return { ...base, month };
        if (config.dateMode === 'year') return { ...base, year };
        return base;
    };

    const loadReport = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const resp = await axios.get(config.endpoint, { params: buildParams() });
            const rows = resp.data?.rows || resp.data?.days || resp.data?.months || resp.data?.data || [];
            setReportData(rows);
            setSummary(resp.data?.summary || resp.data?.totals || null);
        } catch {
            setError('Failed to load report data. Please try again.');
            setReportData([]);
        } finally {
            setLoading(false);
        }
    }, [config, selectedTenant, dateFrom, dateTo, month, year]);

    // auto-load on type change
    useEffect(() => {
        setReportData([]);
        setSummary(null);
        setError(null);
    }, [type]);

    const chartData = useMemo(() => ({
        labels: reportData.map(r => r.date || r.day || r.month || r.label || r.period || ''),
        datasets: [
            {
                label: 'Gross Sales',
                data: reportData.map(r => Number(r.gross_sales || r.gross || 0)),
                borderColor: '#df1160',
                backgroundColor: 'rgba(223,17,96,0.10)',
                borderWidth: 2, tension: 0.35, fill: true, pointRadius: 3,
            },
            {
                label: 'Net Sales',
                data: reportData.map(r => Number(r.net_sales || r.net || 0)),
                borderColor: '#1d437b',
                backgroundColor: 'rgba(29,67,123,0.06)',
                borderWidth: 2, tension: 0.35, fill: true, pointRadius: 3,
            }
        ]
    }), [reportData]);

    const chartOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => `₱${Number(v).toLocaleString()}` } }
        }
    };

    return (
        <Box sx={{ pb: 10 }}>
            {/* Page Header */}
            <Box sx={{ py: 3, mb: 2 }}>
                <Stack direction="row" spacing={2} alignItems="center">
                    <Box sx={{ p: 1.5, bgcolor: 'secondary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(223,17,96,0.2)' }}>
                        <span className="material-symbols-outlined" style={{ fontSize: 28 }}>{config.icon}</span>
                    </Box>
                    <Box>
                        <Typography variant="h2" sx={{ fontWeight: 900, letterSpacing: '-0.02em' }}>{config.title}</Typography>
                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>Sales performance data for the selected period and tenant.</Typography>
                    </Box>
                </Stack>
            </Box>

            {/* Filter Bar */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', p: 3, mb: 4, boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }} flexWrap="wrap">
                    <Stack direction="row" spacing={1} alignItems="center" sx={{ color: 'secondary.main', minWidth: 150 }}>
                        <FilterAltIcon fontSize="small" />
                        <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>Filters</Typography>
                    </Stack>

                    <Autocomplete
                        options={tenants}
                        getOptionLabel={o => o.trade_name || ''}
                        isOptionEqualToValue={(o, v) => o.id === v.id}
                        value={selectedTenant}
                        onChange={(_, v) => setSelectedTenant(v)}
                        onOpen={loadTenants}
                        sx={{ minWidth: 240, flex: 1 }}
                        renderInput={params => <TextField {...params} label="Tenant (All)" size="small" />}
                    />

                    {config.dateMode === 'single' && (
                        <TextField type="date" label="Date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} size="small" InputLabelProps={{ shrink: true }} sx={{ minWidth: 160 }} />
                    )}
                    {config.dateMode === 'range' && (<>
                        <TextField type="date" label="From" value={dateFrom} onChange={e => setDateFrom(e.target.value)} size="small" InputLabelProps={{ shrink: true }} sx={{ minWidth: 160 }} />
                        <TextField type="date" label="To" value={dateTo} onChange={e => setDateTo(e.target.value)} size="small" InputLabelProps={{ shrink: true }} sx={{ minWidth: 160 }} />
                    </>)}
                    {config.dateMode === 'month' && (
                        <TextField type="month" label="Month" value={month} onChange={e => setMonth(e.target.value)} size="small" InputLabelProps={{ shrink: true }} sx={{ minWidth: 180 }} />
                    )}
                    {config.dateMode === 'year' && (
                        <TextField select label="Year" value={year} onChange={e => setYear(e.target.value)} size="small" sx={{ minWidth: 120 }}>
                            {[...Array(5)].map((_, i) => { const y = new Date().getFullYear() - i; return <MenuItem key={y} value={String(y)}>{y}</MenuItem>; })}
                        </TextField>
                    )}

                    <Button variant="contained" color="secondary" onClick={loadReport} disabled={loading} startIcon={loading ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />} sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}>
                        {loading ? 'Loading...' : 'Load Report'}
                    </Button>

                    {summary && (
                        <Button variant="outlined" color="primary" startIcon={<DownloadIcon />}
                            href={`/commercial/reports/sales-report/export?date_from=${dateFrom}&date_to=${dateTo}&tenant_id=${selectedTenant?.id || ''}`}
                            target="_blank"
                            sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}>
                            Export
                        </Button>
                    )}
                </Stack>
            </Box>

            {error && <Alert severity="error" sx={{ mb: 3 }}>{error}</Alert>}

            {/* Metrics */}
            {summary && (
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mb: 4 }} flexWrap="wrap">
                    <MetricTile icon={<BarChartIcon fontSize="small" />} label="Gross Revenue" value={`₱${fmt(summary.gross_sales)}`} sub="Total Period Sales" />
                    <MetricTile icon={<AccountBalanceWalletIcon fontSize="small" />} label="Net Revenue" value={`₱${fmt(summary.net_sales)}`} sub="After Deductions" />
                    <MetricTile icon={<ReceiptLongIcon fontSize="small" />} label="Transactions" value={(summary.transaction_count || 0).toLocaleString()} sub="Verified Volume" />
                    <MetricTile icon={<TrendingUpIcon fontSize="small" />} label="Avg Ticket" value={`₱${fmt((summary.gross_sales || 0) / Math.max(summary.transaction_count || 1, 1))}`} sub="Per Transaction" />
                </Stack>
            )}

            {/* Chart */}
            {reportData.length > 0 && (
                <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', p: 4, mb: 4, minHeight: 340, boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                    <Typography variant="subtitle1" sx={{ fontWeight: 900, mb: 3 }}>Performance Stream</Typography>
                    <Box sx={{ height: 280 }}>
                        <Line data={chartData} options={chartOptions} />
                    </Box>
                </Box>
            )}

            {/* Table */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', overflow: 'hidden', boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                <Box sx={{ p: 3, borderBottom: '1px solid', borderColor: 'divider', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <Typography variant="subtitle1" sx={{ fontWeight: 900 }}>Granular Ledger</Typography>
                    <Box sx={{ px: 2, py: 0.5, bgcolor: 'action.hover', borderRadius: '999px' }}>
                        <Typography variant="caption" sx={{ fontWeight: 800, letterSpacing: '0.06em' }}>{reportData.length} records</Typography>
                    </Box>
                </Box>

                <Box sx={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0' }}>
                                {['Period', 'Gross Sales', 'Net Sales', 'VAT', 'Transactions', 'Status'].map(h => (
                                    <th key={h} style={{ padding: '12px 20px', textAlign: h === 'Period' ? 'left' : 'right', fontWeight: 800, fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8', whiteSpace: 'nowrap' }}>
                                        {h === 'Status' ? <span style={{ display: 'flex', justifyContent: 'center' }}>{h}</span> : h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {reportData.map((row, idx) => (
                                <tr key={idx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                    <td style={{ padding: '12px 20px', fontWeight: 700, color: '#0f172a' }}>{row.hour || row.date || row.day || row.month || row.label || row.period || idx + 1}</td>
                                    <td style={{ padding: '12px 20px', textAlign: 'right', fontWeight: 700, color: '#df1160' }}>₱{fmt(row.gross_sales || row.gross)}</td>
                                    <td style={{ padding: '12px 20px', textAlign: 'right', color: '#475569' }}>₱{fmt(row.net_sales || row.net)}</td>
                                    <td style={{ padding: '12px 20px', textAlign: 'right', color: '#64748b' }}>₱{fmt(row.vat_amount)}</td>
                                    <td style={{ padding: '12px 20px', textAlign: 'right' }}>
                                        <span style={{ padding: '3px 10px', background: '#f1f5f9', borderRadius: 8, fontSize: 11, fontWeight: 800, color: '#475569' }}>
                                            {(row.transaction_count || row.count || 0).toLocaleString()}
                                        </span>
                                    </td>
                                    <td style={{ padding: '12px 20px', textAlign: 'center' }}>
                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', background: '#ecfdf5', color: '#059669', borderRadius: 8, fontSize: 10, fontWeight: 800, textTransform: 'uppercase' }}>
                                            <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#10b981', display: 'inline-block' }} />
                                            Verified
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {reportData.length === 0 && !loading && (
                                <tr>
                                    <td colSpan={6} style={{ padding: '60px 20px', textAlign: 'center', color: '#cbd5e1' }}>
                                        <span className="material-symbols-outlined" style={{ fontSize: 48, display: 'block', marginBottom: 8 }}>database_off</span>
                                        <p style={{ fontWeight: 800, fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                                            {loading ? 'Loading...' : 'Select filters and click Load Report'}
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </Box>
            </Box>
        </Box>
    );
};

export default SalesReportPage;
