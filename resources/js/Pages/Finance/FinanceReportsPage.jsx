import React, { useState, useCallback } from 'react';
import axios from 'axios';
import {
    Box,
    Typography,
    Stack,
    Button,
    CircularProgress,
    TextField,
    Autocomplete,
    Alert
} from '@mui/material';
import DownloadIcon from '@mui/icons-material/Download';
import SyncIcon from '@mui/icons-material/Sync';
import DescriptionIcon from '@mui/icons-material/Description';
import FilterAltIcon from '@mui/icons-material/FilterAlt';

// ─── Helpers ──────────────────────────────────────────────────────────────────
const fmt = (v, decimals = 2) =>
    Number(v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

const php = (v) => `P${fmt(v)}`;

// ─── CSMR Table ───────────────────────────────────────────────────────────────
const CSMR_TABLE_STYLES = `
.csmr-wrap { overflow-x: auto; font-family: Arial, sans-serif; font-size: 11px; }
.csmr-table { border-collapse: collapse; min-width: 1200px; width: 100%; }
.csmr-table th, .csmr-table td {
    border: 1px solid #333;
    padding: 3px 5px;
    text-align: center;
    white-space: nowrap;
}
.csmr-table thead th { background: #fff; font-weight: bold; font-size: 10px; }
.csmr-table .section-header { background: #d9d9d9; font-weight: bold; }
.csmr-table .total-row td { background: #f0f0f0; font-weight: bold; }
.csmr-table .date-col { text-align: left; }
.csmr-table tbody tr:hover td { background: #f9f9f9; }
.csmr-logo { text-align: right; padding: 8px 0; }
.csmr-logo img { height: 48px; object-fit: contain; }
.csmr-title { text-align: center; margin: 12px 0 8px; }
.csmr-title h2 { font-size: 14px; font-weight: bold; letter-spacing: 1px; }
.csmr-title p { font-size: 11px; }
.less-section { margin-top: 8px; font-size: 12px; }
.less-section table { width: 100%; }
.less-section td { padding: 2px 4px; }
.less-section .label { width: 60%; }
.less-section .value { width: 40%; text-align: right; }
`;

const CsmrTable = ({ reportData, tenantName, month }) => {
    if (!reportData) return null;

    const { daily_totals = {}, totals = {} } = reportData;
    const rows = Object.entries(daily_totals).sort(([a], [b]) => a.localeCompare(b));

    const monthLabel = month
        ? new Date(month + '-01').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
        : '';

    return (
        <>
            <style>{CSMR_TABLE_STYLES}</style>

            {/* Logo */}
            <div className="csmr-logo">
                <img src="/images/mwm_logo.png" alt="MWM" />
            </div>

            {/* Title */}
            <div className="csmr-title">
                <h2>{tenantName || 'Tradename / Branch'}</h2>
                <h2>CERTIFIED MONTHLY SALES REPORT</h2>
                <p>For the month of {monthLabel}</p>
            </div>

            {/* Main Table */}
            <div className="csmr-wrap">
                <table className="csmr-table">
                    <thead>
                        <tr>
                            <th rowSpan={3} style={{ width: 70 }}>Date</th>
                            {/* Net Sales group: Vatable, SC Exempt, VAT */}
                            <th colSpan={3} className="section-header">Net Sales</th>
                            {/* Sales Discount group: Promo(W/WO), Employee, Senior, PWD, VIP, Other Tax */}
                            <th colSpan={7} className="section-header">Sales Discount</th>
                            {/* Service Charge group: Distributed, Retained */}
                            <th colSpan={2} className="section-header">Service Charge</th>
                            <th rowSpan={3} style={{ minWidth: 80 }}>Gross Sales</th>
                        </tr>
                        <tr>
                            <th>Vatable Trans.<br /><small>(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</small></th>
                            <th>SC Vat Exempt Trans.<br /><small>(NET OF DISC. SERVICE CHARGE AND LOCAL TAX)</small></th>
                            <th>Value Added Tax (VAT)</th>
                            <th colSpan={2}>Promo</th>
                            <th>Employee's Discount</th>
                            <th>Senior Citizen's</th>
                            <th>PWD Disc.</th>
                            <th>VIP Cards<br /><small>(if any)</small></th>
                            <th>Other Tax<br /><small>(Local Tax)</small></th>
                            <th colSpan={2}>Service Charge</th>
                        </tr>
                        <tr>
                            {/* Promo sub: with / without */}
                            <th></th><th></th><th></th>
                            <th style={{ fontSize: 9 }}>With<br />Approval</th>
                            <th style={{ fontSize: 9 }}>Without<br />Approval</th>
                            <th></th><th></th><th></th><th></th><th></th>
                            {/* Service Charge sub-cols */}
                            <th style={{ fontSize: 9 }}>Distributed<br />to Employees</th>
                            <th style={{ fontSize: 9 }}>Retained by<br />Management</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td>-</td>
                                {Array.from({ length: 14 }).map((_, i) => <td key={i}>0.00</td>)}
                            </tr>
                        ) : (
                            rows.map(([date, d]) => (
                                <tr key={date}>
                                    <td className="date-col">{new Date(date + 'T00:00:00').getDate()}</td>
                                    <td>{fmt(d.vatable_sales)}</td>
                                    <td>{fmt(d.sc_vat_exempt_sales)}</td>
                                    <td>{fmt(d.vat_amount)}</td>
                                    <td>{fmt(d.promo_with_approval)}</td>
                                    <td>{fmt(d.promo_without_approval)}</td>
                                    <td>{fmt(d.employee_discount)}</td>
                                    <td>{fmt(d.senior_discount)}</td>
                                    <td>{fmt(d.pwd_discount)}</td>
                                    <td>{fmt(d.vip_discount)}</td>
                                    <td>{fmt(d.other_tax)}</td>
                                    <td>{fmt(d.service_charge_distributed)}</td>
                                    <td>{fmt(d.service_charge_retained)}</td>
                                    <td>{fmt(d.gross_sales)}</td>
                                </tr>
                            ))
                        )}

                        {/* Totals row */}
                        <tr className="total-row">
                            <td className="date-col">Total</td>
                            <td><strong>{fmt(totals.vatable_sales)}</strong></td>
                            <td><strong>{fmt(totals.sc_vat_exempt_sales)}</strong></td>
                            <td><strong>{fmt(totals.vat_amount)}</strong></td>
                            <td>{fmt(totals.promo_with_approval)}</td>
                            <td>{fmt(totals.promo_without_approval)}</td>
                            <td>{fmt(totals.employee_discount)}</td>
                            <td><strong>{fmt(totals.senior_discount)}</strong></td>
                            <td><strong>{fmt(totals.pwd_discount)}</strong></td>
                            <td>{fmt(totals.vip_discount)}</td>
                            <td>{fmt(totals.other_tax)}</td>
                            <td>{fmt(totals.service_charge_distributed)}</td>
                            <td>{fmt(totals.service_charge_retained)}</td>
                            <td><strong>{fmt(totals.gross_sales)}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {/* Summary Sections */}
            <div className="less-section">
                <table style={{ borderCollapse: 'collapse' }}>
                    <tbody>
                        <tr>
                            <td className="label">Less:</td>
                            <td />
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Promo Discounts With Approval</td>
                            <td className="value">{php(totals.promo_with_approval)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Promo Discounts Without Approval</td>
                            <td className="value">{php(totals.promo_without_approval)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Employee's Discount</td>
                            <td className="value">{php(totals.employee_discount)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>VIP Cards</td>
                            <td className="value">{php(totals.vip_discount)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>SC Vat Exempt Sales</td>
                            <td className="value">{php(totals.sc_vat_exempt_sales)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Senior Citizen's & PWD Discount</td>
                            <td className="value">{php(totals.senior_pwd)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Other Tax (Local Tax)</td>
                            <td className="value">{php(totals.other_tax)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Service Charge Distributed to Employees</td>
                            <td className="value">{php(totals.service_charge_distributed)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Service Charge Retained by Management</td>
                            <td className="value">{php(totals.service_charge_retained)}</td>
                        </tr>
                        <tr style={{ borderTop: '2px solid #333', fontWeight: 'bold' }}>
                            <td className="label">Net Sales</td>
                            <td className="value">{php(totals.net_sales)}</td>
                        </tr>
                        <tr>
                            <td className="label">Less 12% VAT</td>
                            <td className="value">({php(totals.vat_amount)})</td>
                        </tr>
                        <tr style={{ borderBottom: '1px solid #333', fontWeight: 'bold' }}>
                            <td className="label">Net ex-VAT</td>
                            <td className="value">{php(totals.net_ex_vat)}</td>
                        </tr>
                        {/* Add Section */}
                        <tr>
                            <td className="label" style={{ paddingTop: 8 }}>Add:</td>
                            <td />
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>SC Vat Exempt Sales</td>
                            <td className="value">{php(totals.sc_vat_exempt_sales)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Promo Discounts Without Approval</td>
                            <td className="value">{php(totals.promo_without_approval)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Other Tax (Local Tax)</td>
                            <td className="value">{php(totals.other_tax)}</td>
                        </tr>
                        <tr>
                            <td className="label" style={{ paddingLeft: 32 }}>Service Charge Retained by Management</td>
                            <td className="value">{php(totals.service_charge_retained)}</td>
                        </tr>
                        <tr style={{ borderTop: '2px solid #333', fontWeight: 'bold' }}>
                            <td className="label">Net Sales Subject to Percentage Rent</td>
                            <td className="value">{php(totals.net_subject_to_rent)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </>
    );
};

// ─── Main Page ────────────────────────────────────────────────────────────────
const FinanceReportsPage = () => {
    const [tenants, setTenants] = useState([]);
    const [tenantsLoaded, setTenantsLoaded] = useState(false);
    const [selectedTenant, setSelectedTenant] = useState(null);
    const [reportMonth, setReportMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [loading, setLoading] = useState(false);
    const [reportData, setReportData] = useState(null);
    const [error, setError] = useState(null);

    // Lazy-load tenants on first open of the dropdown
    const loadTenants = useCallback(async () => {
        if (tenantsLoaded) return;
        try {
            const resp = await axios.get('/commercial/reports/tenants');
            setTenants(resp.data || []);
            setTenantsLoaded(true);
        } catch {
            setTenants([]);
        }
    }, [tenantsLoaded]);

    const generateReport = useCallback(async () => {
        if (!selectedTenant) return;
        setLoading(true);
        setError(null);
        try {
            const resp = await axios.get('/reports/data', {
                params: { tenant: selectedTenant.id, month: reportMonth }
            });
            setReportData(resp.data);
        } catch (e) {
            setError('Failed to load report data. Please try again.');
        } finally {
            setLoading(false);
        }
    }, [selectedTenant, reportMonth]);

    const handleExport = () => {
        if (!selectedTenant) return;
        const [year, month] = reportMonth.split('-');
        const url = `/finance/reports/export?year=${year}&month=${month}&tenant=${selectedTenant.id}`;
        
        // Use an anchor tag to ensure download behavior is consistent
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `SalesReport_${year}_${month}.xlsx`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    return (
        <Box sx={{ pb: 10 }}>
            {/* Header */}
            <Box sx={{ py: 3, mb: 2 }}>
                <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1 }}>
                    <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(29,67,155,0.2)' }}>
                        <DescriptionIcon sx={{ fontSize: 28 }} />
                    </Box>
                    <Box>
                        <Typography variant="h2" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.02em' }}>
                            Finance Reports
                        </Typography>
                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                            Generate the Certified Monthly Sales Report (CSMR) per tenant.
                        </Typography>
                    </Box>
                </Stack>
            </Box>

            {/* Filter Bar */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', p: 3, mb: 4, boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }}>
                    <Stack direction="row" spacing={1} alignItems="center" sx={{ color: 'secondary.main', minWidth: 150 }}>
                        <FilterAltIcon fontSize="small" />
                        <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                            Filter Command
                        </Typography>
                    </Stack>

                    <Autocomplete
                        options={tenants}
                        getOptionLabel={(o) => o.trade_name || ''}
                        isOptionEqualToValue={(o, v) => o.id === v.id}
                        value={selectedTenant}
                        onChange={(_, v) => setSelectedTenant(v)}
                        onOpen={loadTenants}
                        sx={{ minWidth: 280, flex: 1 }}
                        renderInput={(params) => (
                            <TextField {...params} label="Trade Name / Business Unit" size="small" />
                        )}
                    />

                    <TextField
                        type="month"
                        label="Report Month"
                        value={reportMonth}
                        onChange={(e) => setReportMonth(e.target.value)}
                        size="small"
                        InputLabelProps={{ shrink: true }}
                        sx={{ minWidth: 180 }}
                    />

                    <Button
                        variant="contained"
                        color="secondary"
                        onClick={generateReport}
                        disabled={!selectedTenant || loading}
                        startIcon={loading ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />}
                        sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}
                    >
                        {loading ? 'Generating...' : 'Generate Report'}
                    </Button>

                    {reportData && (
                        <Button
                            variant="outlined"
                            color="primary"
                            onClick={handleExport}
                            startIcon={<DownloadIcon />}
                            sx={{ borderRadius: '12px', fontWeight: 800, px: 3, whiteSpace: 'nowrap' }}
                        >
                            Export Excel
                        </Button>
                    )}
                </Stack>
            </Box>

            {/* Error */}
            {error && <Alert severity="error" sx={{ mb: 3 }}>{error}</Alert>}

            {/* Report Output */}
            <Box sx={{ bgcolor: 'white', borderRadius: '20px', border: '1px solid', borderColor: 'divider', p: 4, minHeight: 300, boxShadow: '0 4px 16px rgba(0,0,0,0.04)' }}>
                {!reportData && !loading && (
                    <Box sx={{ py: 8, textAlign: 'center' }}>
                        <DescriptionIcon sx={{ fontSize: 64, color: 'divider', mb: 2 }} />
                        <Typography sx={{ color: 'text.disabled', fontWeight: 600 }}>
                            Select a tenant and report month, then click Generate Report.
                        </Typography>
                    </Box>
                )}

                {loading && (
                    <Box sx={{ py: 8, textAlign: 'center' }}>
                        <CircularProgress color="secondary" />
                        <Typography sx={{ mt: 2, color: 'text.secondary' }}>Generating report...</Typography>
                    </Box>
                )}

                {reportData && !loading && (
                    <CsmrTable
                        reportData={reportData}
                        tenantName={selectedTenant?.trade_name}
                        month={reportMonth}
                    />
                )}
            </Box>
        </Box>
    );
};

export default FinanceReportsPage;
