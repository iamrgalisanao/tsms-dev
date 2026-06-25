import React, { useState, useCallback } from 'react';
import axios from 'axios';
import {
    Box, Typography, Stack, Button, CircularProgress,
    TextField, Autocomplete, Alert
} from '@mui/material';
import SyncIcon from '@mui/icons-material/Sync';
import DownloadIcon from '@mui/icons-material/Download';
import FilterAltIcon from '@mui/icons-material/FilterAlt';
import ScheduleIcon from '@mui/icons-material/Schedule';

const fmt = (v) => Number(v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().split('T')[0];

const StatPill = ({ label, value }) => (
    <Box sx={{ bgcolor: 'white', borderRadius: '14px', border: '1px solid', borderColor: 'divider', px: 3, py: 2, flex: 1, minWidth: 140 }}>
        <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'text.secondary' }}>{label}</Typography>
        <Typography variant="h6" sx={{ fontWeight: 900 }}>{value}</Typography>
    </Box>
);

const HourlyReportPage = () => {
    const [date, setDate] = useState(today());
    const [tenants, setTenants] = useState([]);
    const [tenantsLoaded, setTenantsLoaded] = useState(false);
    const [selectedTenant, setSelectedTenant] = useState(null);
    const [loading, setLoading] = useState(false);
    const [reportData, setReportData] = useState([]);
    const [totals, setTotals] = useState(null);
    const [error, setError] = useState(null);

    const loadTenants = useCallback(async () => {
        if (tenantsLoaded) return;
        try {
            const r = await axios.get('/commercial/reports/tenants');
            setTenants(r.data || []);
            setTenantsLoaded(true);
        } catch { setTenants([]); }
    }, [tenantsLoaded]);

    const METRIC_KEYS = [
        'gross_sales', 'vatable_sales', 'vat_amount', 'sc_pwd_discount',
        'regular_discount', 'void', 'net_sales',
        'cash_payment', 'card_payment', 'other_tender',
        'net_sales_percentage_rent', 'transaction_count', 'guest_count'
    ];

    const calcTotals = (data) => {
        if (!data?.length) return null;
        const sums = {};
        METRIC_KEYS.forEach(k => { sums[k] = data.reduce((a, r) => a + (Number(r[k]) || 0), 0); });
        return sums;
    };

    const loadReport = useCallback(async () => {
        if (!selectedTenant) return;
        setLoading(true);
        setError(null);
        try {
            const resp = await axios.get('/commercial/reports/transactions/hourly', {
                params: { date, tenant_id: selectedTenant.id }
            });
            const data = resp.data?.data || resp.data?.rows || [];
            setReportData(data);
            setTotals(calcTotals(data));
        } catch {
            setError('Failed to load hourly data. Please try again.');
            setReportData([]);
            setTotals(null);
        } finally {
            setLoading(false);
        }
    }, [date, selectedTenant]);

    const handleExport = () => {
        const params = new URLSearchParams({
            report_type: 'hourly',
            date,
            tenant_id: selectedTenant?.id || ''
        });
        window.open(`/commercial/reports/export?${params.toString()}`, '_blank');
    };

    // Build 24-hour rows — fill gaps with zeros
    const hourRows = Array.from({ length: 24 }, (_, i) => {
        const hourStr = `${String(i).padStart(2, '0')}:00`;
        return reportData.find(r => r.hour === hourStr) || { hour: hourStr };
    });

    const peakRow = totals && reportData.length
        ? reportData.reduce((max, r) => Number(r.gross_sales || 0) > Number(max.gross_sales || 0) ? r : max, reportData[0])
        : null;

    return (
        <Box sx={{ pb: 10 }}>
            {/* Header */}
            <Stack direction="row" spacing={2.5} alignItems="center" sx={{ py: 3, mb: 2 }}>
                <Box sx={{ p: 1.5, bgcolor: 'secondary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(223,17,96,0.2)' }}>
                    <ScheduleIcon sx={{ fontSize: 28 }} />
                </Box>
                <Box>
                    <Typography variant="h2" sx={{ fontWeight: 900, letterSpacing: '-0.02em' }}>Hourly Velocity Report</Typography>
                    <Typography variant="body2" sx={{ color: 'text.secondary' }}>24-hour sales and transaction distribution per tenant per day.</Typography>
                </Box>
            </Stack>

            {/* Filters */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', p: 3, mb: 4, boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }} flexWrap="wrap">
                    <Stack direction="row" spacing={1} alignItems="center" sx={{ color: 'secondary.main', minWidth: 130 }}>
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
                        sx={{ minWidth: 260, flex: 1 }}
                        renderInput={p => <TextField {...p} label="Select Tenant *" size="small" />}
                    />

                    <TextField type="date" label="Date" value={date} onChange={e => setDate(e.target.value)} size="small" InputLabelProps={{ shrink: true }} sx={{ minWidth: 160 }} />

                    <Button
                        variant="contained" color="secondary"
                        onClick={loadReport}
                        disabled={loading || !selectedTenant}
                        startIcon={loading ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />}
                        sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}>
                        {loading ? 'Loading...' : 'Load Report'}
                    </Button>

                    {totals && (
                        <Button variant="outlined" onClick={handleExport} startIcon={<DownloadIcon />}
                            sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}>
                            Export Excel
                        </Button>
                    )}
                </Stack>
            </Box>

            {error && <Alert severity="error" sx={{ mb: 3 }}>{error}</Alert>}

            {/* Metrics */}
            {totals && (
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mb: 4 }} flexWrap="wrap">
                    <StatPill label="Total Gross" value={`₱${fmt(totals.gross_sales)}`} />
                    <StatPill label="Total Net" value={`₱${fmt(totals.net_sales)}`} />
                    <StatPill label="Avg Hourly Gross" value={`₱${fmt(totals.gross_sales / 24)}`} />
                    <StatPill label="Total Transactions" value={(totals.transaction_count || 0).toLocaleString()} />
                    <StatPill label="Peak Hour" value={peakRow?.hour || '—'} />
                </Stack>
            )}

            {/* Table */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', overflow: 'hidden', boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                <Box sx={{ p: 3, borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Typography variant="subtitle1" sx={{ fontWeight: 900 }}>24-Hour Distribution</Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.06em' }}>Granular hourly ledger</Typography>
                </Box>

                <Box sx={{ overflowX: 'auto', maxHeight: 640, overflowY: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                        <thead style={{ position: 'sticky', top: 0, zIndex: 10, background: '#f8fafc' }}>
                            <tr style={{ borderBottom: '2px solid #e2e8f0' }}>
                                {['Hour', 'Gross Sales', 'Vatable', 'VAT', 'SC/PWD', 'Net Sales', 'Cash', 'Card', 'Other', 'TX Count', 'Guests'].map(h => (
                                    <th key={h} style={{ padding: '10px 14px', textAlign: h === 'Hour' ? 'center' : 'right', fontWeight: 800, fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#94a3b8', whiteSpace: 'nowrap' }}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {hourRows.map(row => (
                                <tr key={row.hour} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                    <td style={{ padding: '10px 14px', textAlign: 'center', fontWeight: 800, color: '#0f172a', background: '#f8fafc' }}>{row.hour}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', fontWeight: 700, color: '#df1160' }}>₱{fmt(row.gross_sales)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#475569' }}>₱{fmt(row.vatable_sales)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#64748b' }}>₱{fmt(row.vat_amount)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#64748b' }}>₱{fmt(row.sc_pwd_discount)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', fontWeight: 700, color: '#1d437b' }}>₱{fmt(row.net_sales)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#059669' }}>₱{fmt(row.cash_payment)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#2563eb' }}>₱{fmt(row.card_payment)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right', color: '#64748b' }}>₱{fmt(row.other_tender)}</td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                        <span style={{ padding: '2px 8px', background: '#f1f5f9', borderRadius: 6, fontWeight: 800 }}>{row.transaction_count || 0}</span>
                                    </td>
                                    <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                        <span style={{ padding: '2px 8px', background: '#f1f5f9', borderRadius: 6, fontWeight: 800 }}>{row.guest_count || 0}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {totals && (
                            <tfoot style={{ position: 'sticky', bottom: 0, background: '#e2e8f0', fontWeight: 900 }}>
                                <tr style={{ borderTop: '2px solid #cbd5e1' }}>
                                    <td style={{ padding: '12px 14px', textAlign: 'center', fontSize: 10, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em' }}>TOTALS</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right', color: '#df1160' }}>₱{fmt(totals.gross_sales)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>₱{fmt(totals.vatable_sales)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>₱{fmt(totals.vat_amount)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>₱{fmt(totals.sc_pwd_discount)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right', color: '#1d437b' }}>₱{fmt(totals.net_sales)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right', color: '#059669' }}>₱{fmt(totals.cash_payment)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right', color: '#2563eb' }}>₱{fmt(totals.card_payment)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>₱{fmt(totals.other_tender)}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>{totals.transaction_count}</td>
                                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>{totals.guest_count}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>

                    {reportData.length === 0 && !loading && (
                        <Box sx={{ py: 8, textAlign: 'center', color: 'text.disabled' }}>
                            <span className="material-symbols-outlined" style={{ fontSize: 48, display: 'block', marginBottom: 8 }}>data_exploration</span>
                            <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                                Select a tenant and date, then click Load Report
                            </Typography>
                        </Box>
                    )}
                </Box>
            </Box>
        </Box>
    );
};

export default HourlyReportPage;
