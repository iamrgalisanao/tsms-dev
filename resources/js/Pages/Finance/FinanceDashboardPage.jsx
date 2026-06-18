import React, { useState, useCallback, useMemo } from 'react';
import {
    Box,
    Typography,
    Grid,
    Button,
    Stack,
    Breadcrumbs,
    Link as MuiLink,
    Alert,
    Card,
    CardContent,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    Divider,
    Chip,
    List,
    ListItem,
    ListItemIcon,
    ListItemText,
    Select,
    MenuItem,
    FormControl,
    InputLabel
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import DashboardIcon from '@mui/icons-material/Dashboard';
import SyncIcon from '@mui/icons-material/Sync';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import TrendingDownIcon from '@mui/icons-material/TrendingDown';
import TimelineIcon from '@mui/icons-material/Timeline';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import HubIcon from '@mui/icons-material/Hub';
import AnalyticsIcon from '@mui/icons-material/Analytics';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import BusinessIcon from '@mui/icons-material/Business';
import GetAppIcon from '@mui/icons-material/GetApp';
import VisibilityIcon from '@mui/icons-material/Visibility';
import ErrorIcon from '@mui/icons-material/Error';
import WarningIcon from '@mui/icons-material/Warning';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

import TransactionChart from '../../Components/dashboard/TransactionChart';
import FinanceKpiCard from '../../features/finance/components/FinanceKpiCard';
import FinanceLoadingSkeleton from '../../features/finance/components/FinanceLoadingSkeleton';
import RevenueCompositionChart from '../../features/finance/components/RevenueCompositionChart';
import { formatCurrency } from '../../features/finance/components/financeFormat';
import { useFinanceDashboard } from '../../features/finance/hooks/useFinanceDashboard';

const FinanceDashboardPage = () => {
    const [dateRange, setDateRange] = useState('7'); // '7' or '30' days
    const { data, isLoading, isFetching, refetch } = useFinanceDashboard(dateRange);

    const metrics = data?.metrics;
    const charts = data?.charts;

    const handleForceSync = useCallback(() => {
        refetch();
    }, [refetch]);

    const handleExport = (endpoint, params = {}) => {
        const query = new URLSearchParams(params).toString();
        window.open(`${endpoint}?${query}`, '_blank');
    };

    // Calculate Reconciled Percentage
    const reconciledPercentage = useMemo(() => {
        if (!metrics?.reconciliation?.total) return '0.0%';
        const rate = (metrics.reconciliation.reconciled / metrics.reconciliation.total) * 100;
        return `${rate.toFixed(1)}%`;
    }, [metrics]);

    // Calculate Leakage Percentages
    const refundsPercentage = useMemo(() => {
        const gross = metrics?.total_sales?.current || 0;
        if (gross <= 0) return '0.00%';
        const refVal = metrics?.revenue_composition?.refunds || 0;
        return `${((refVal / gross) * 100).toFixed(2)}%`;
    }, [metrics]);

    const discountsPercentage = useMemo(() => {
        const gross = metrics?.total_sales?.current || 0;
        if (gross <= 0) return '0.00%';
        const discVal = metrics?.revenue_composition?.discounts || 0;
        return `${((discVal / gross) * 100).toFixed(2)}%`;
    }, [metrics]);

    const voidsPercentage = useMemo(() => {
        const total = metrics?.reconciliation?.total || 0;
        if (total <= 0) return '0.00%';
        const voids = metrics?.voided_transactions?.current || 0;
        return `${((voids / total) * 100).toFixed(2)}%`;
    }, [metrics]);

    // Calculate Due Dates & Timestamps
    const csmrDueDate = useMemo(() => {
        const d = new Date();
        d.setMonth(d.getMonth() + 1);
        return `${d.toLocaleString('default', { month: 'long' })} 5`;
    }, []);

    // Calculate Chart visual metrics (Peak Revenue and Average daily revenue)
    const chartStats = useMemo(() => {
        if (!charts?.sales || charts.sales.length === 0) {
            return { peakSales: 0, peakSalesDay: 'N/A', avgDailyRevenue: 0 };
        }
        const peakSales = Math.max(...charts.sales);
        const peakSalesIndex = charts.sales.indexOf(peakSales);
        const peakSalesDay = peakSalesIndex !== -1 ? charts.labels[peakSalesIndex] : 'N/A';
        const avgDailyRevenue = charts.sales.reduce((a, b) => a + b, 0) / charts.sales.length;
        return { peakSales, peakSalesDay, avgDailyRevenue };
    }, [charts]);

    if (isLoading) {
        return <FinanceLoadingSkeleton />;
    }

    return (
        <Box sx={{ pb: 10 }}>
            {/* Breadcrumbs & Header */}
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        FINANCE
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>DASHBOARD COMMAND</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', lg: 'center' }} sx={{ mb: 6 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(29, 67, 155, 0.25)' }}>
                                <DashboardIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Finance Command Center
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Financial health, reconciliations, exceptions, and compliance operations.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} alignItems={{ xs: 'stretch', sm: 'center' }} sx={{ width: { xs: '100%', sm: 'auto' } }}>
                        {/* Data Freshness Indicator in Header */}
                        <Stack direction="row" spacing={3} alignItems="center" sx={{ bgcolor: 'rgba(255,255,255,0.4)', px: 2, py: 1, borderRadius: '16px', border: '1px solid rgba(255,255,255,0.6)' }}>
                            <Stack direction="row" spacing={1} alignItems="center">
                                <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'success.main', boxShadow: '0 0 10px #10B981' }} />
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                                    Ecosystem Status: Healthy
                                </Typography>
                            </Stack>
                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                Last Sync: <Box component="span" sx={{ fontWeight: 900, color: 'text.primary' }}>{metrics?.sync_status?.last_sync || 'N/A'}</Box>
                            </Typography>
                        </Stack>

                        <Stack direction="row" spacing={2}>
                            {/* Date Range Selector */}
                            <FormControl size="small" sx={{ minWidth: 140 }}>
                                <InputLabel id="date-range-label">Period</InputLabel>
                                <Select
                                    labelId="date-range-label"
                                    value={dateRange}
                                    label="Period"
                                    onChange={(e) => {
                                        setDateRange(e.target.value);
                                    }}
                                    sx={{ borderRadius: '12px', bgcolor: 'background.paper' }}
                                >
                                    <MenuItem value="7">Last 7 Days</MenuItem>
                                    <MenuItem value="30">Last 30 Days</MenuItem>
                                </Select>
                            </FormControl>

                            {/* Force Sync button */}
                            <Button
                                variant="contained"
                                onClick={handleForceSync}
                                disabled={isFetching}
                                startIcon={<SyncIcon />}
                                className="pitx-gradient"
                                sx={{
                                    borderRadius: '16px',
                                    px: 4,
                                    py: 1.25,
                                    fontWeight: 900,
                                    fontSize: '0.75rem',
                                    letterSpacing: '0.1em',
                                    textTransform: 'uppercase',
                                    color: 'white',
                                    boxShadow: '0 8px 25px rgba(29, 67, 155, 0.25)',
                                    '&:hover': { opacity: 0.9 }
                                }}
                            >
                                {isFetching ? 'Syncing...' : 'Force Sync'}
                            </Button>
                        </Stack>
                    </Stack>
                </Stack>
            </Box>

            {/* KPI Cards Row */}
            <Grid container spacing={4} sx={{ mb: 4 }}>
                <Grid item xs={12} sm={6} lg={3}>
                    <FinanceKpiCard
                        title="Gross Sales"
                        value={formatCurrency(metrics?.total_sales?.current || 0)}
                        subtitle={`vs previous ${dateRange} days`}
                        trend={metrics?.total_sales?.trend}
                        trendDirection={metrics?.total_sales?.trend < 0 ? 'down' : 'up'}
                        trendColor={metrics?.total_sales?.trend < 0 ? 'error.main' : 'success.main'}
                        icon={<TrendingUpIcon sx={{ fontSize: 24 }} />}
                        tooltip="Total gross sales recorded by POS terminals before any tax exemptions, discounts, or processing adjustments."
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <FinanceKpiCard
                        title="Net Sales"
                        value={formatCurrency(metrics?.total_net_sales?.current || 0)}
                        subtitle={`vs previous ${dateRange} days`}
                        trend={metrics?.total_net_sales?.trend}
                        trendDirection={metrics?.total_net_sales?.trend < 0 ? 'down' : 'up'}
                        trendColor={metrics?.total_net_sales?.trend < 0 ? 'error.main' : 'success.main'}
                        icon={<AnalyticsIcon sx={{ fontSize: 24 }} />}
                        tooltip="Total net revenue after deducting VAT, VAT exemptions, senior/PWD discounts, and structural service charges."
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <FinanceKpiCard
                        title="Reconciled"
                        value={`${metrics?.reconciliation?.reconciled?.toLocaleString() || 0} / ${metrics?.reconciliation?.total?.toLocaleString() || 0}`}
                        subtitle="Queue completion rate"
                        trend={parseFloat(reconciledPercentage)}
                        trendDirection={parseFloat(reconciledPercentage) < 99.5 ? 'down' : 'up'}
                        trendColor={parseFloat(reconciledPercentage) < 99.5 ? 'warning.main' : 'success.main'}
                        icon={<CheckCircleIcon sx={{ fontSize: 24 }} />}
                        tooltip="Total successfully processed transactions through the sharded queue system. Excludes pending ingestion and failed validator exceptions."
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <FinanceKpiCard
                        title="Validation Exceptions"
                        value={metrics?.exceptions?.total_exceptions || 0}
                        subtitle="Unresolved anomalies"
                        trend={null}
                        gradient={metrics?.exceptions?.total_exceptions > 0 ? 'linear-gradient(135deg, #EF4444 0%, #DC2626 100%)' : null}
                        icon={<ErrorIcon sx={{ fontSize: 24 }} />}
                        onClick={() => window.location.href = `/transactions?status=FAILED`}
                        tooltip="Total schema anomalies, duplicate checksums, or VAT rounding calculation errors requiring manual operations intervention."
                    />
                </Grid>
            </Grid>

            {/* Secondary KPI Row: Financial Leakage Metrics */}
            <Grid container spacing={4} sx={{ mb: 6 }}>
                <Grid item xs={12} sm={4}>
                    <FinanceKpiCard
                        title="Refunds"
                        value={formatCurrency(metrics?.revenue_composition?.refunds || 0)}
                        subtitle={`${refundsPercentage} of gross revenue`}
                        icon={<ReceiptLongIcon sx={{ fontSize: 24 }} />}
                        tooltip="Total sales returned or refunded. Monitored to detect transaction leakage and merchant compliance issues."
                    />
                </Grid>
                <Grid item xs={12} sm={4}>
                    <FinanceKpiCard
                        title="Discounts"
                        value={formatCurrency(metrics?.revenue_composition?.discounts || 0)}
                        subtitle={`${discountsPercentage} of gross revenue`}
                        icon={<TrendingDownIcon sx={{ fontSize: 24 }} />}
                        tooltip="Sum of senior citizen, PWD, promotional, and regular merchant discounts deducted from gross transactions."
                    />
                </Grid>
                <Grid item xs={12} sm={4}>
                    <FinanceKpiCard
                        title="Voided Transactions"
                        value={metrics?.voided_transactions?.current?.toLocaleString() || 0}
                        subtitle={`${voidsPercentage} of total volume`}
                        trend={metrics?.voided_transactions?.trend}
                        trendDirection={metrics?.voided_transactions?.trend < 0 ? 'down' : 'up'}
                        trendColor={metrics?.voided_transactions?.trend < 0 ? 'success.main' : 'error.main'}
                        icon={<ErrorIcon sx={{ fontSize: 24 }} />}
                        tooltip="Total transactions voided at point-of-sale. High void rates may indicate operational errors or transaction fraud."
                    />
                </Grid>
            </Grid>

            {/* Financial Alerts Section */}
            <Box className="glass-card" sx={{ p: 4, borderRadius: '24px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 8px 30px rgba(0,0,0,0.03)', mb: 6 }}>
                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em', mb: 3, display: 'flex', alignItems: 'center', gap: 1 }}>
                    <WarningIcon sx={{ color: 'warning.main' }} />
                    FINANCE ALERTS
                </Typography>
                <Grid container spacing={3}>
                    <Grid item xs={12} md={6} lg={3}>
                        <Alert 
                            severity={metrics?.exceptions?.failed_reconciliations > 0 ? "error" : "success"}
                            sx={{ borderRadius: '16px', fontWeight: 700 }}
                            action={metrics?.exceptions?.failed_reconciliations > 0 && (
                                <Button color="inherit" size="small" onClick={() => window.location.href = '/transactions?status=FAILED'}>
                                    REVIEW
                                </Button>
                            )}
                        >
                            {metrics?.exceptions?.failed_reconciliations > 0 
                                ? `${metrics.exceptions.failed_reconciliations} Failed Reconciliations`
                                : "No Failed Reconciliations"
                            }
                        </Alert>
                    </Grid>
                    <Grid item xs={12} md={6} lg={3}>
                        <Alert 
                            severity={metrics?.exceptions?.missing_uploads > 0 ? "warning" : "success"}
                            sx={{ borderRadius: '16px', fontWeight: 700 }}
                        >
                            {metrics?.exceptions?.missing_uploads > 0 
                                ? `${metrics.exceptions.missing_uploads} Missing Uploads`
                                : "Upload Ingestion Synced"
                            }
                        </Alert>
                    </Grid>
                    <Grid item xs={12} md={6} lg={3}>
                        <Alert 
                            severity={metrics?.reconciliation?.pending > 0 ? "warning" : "success"}
                            sx={{ borderRadius: '16px', fontWeight: 700 }}
                        >
                            {metrics?.reconciliation?.pending > 0 
                                ? `${metrics.reconciliation.pending} Unprocessed Transactions`
                                : "All Transactions Processed"
                            }
                        </Alert>
                    </Grid>
                    <Grid item xs={12} md={6} lg={3}>
                        <Alert 
                            severity={metrics?.compliance?.csmr_ready ? "success" : "info"}
                            icon={<CheckCircleIcon fontSize="inherit" />}
                            sx={{ borderRadius: '16px', fontWeight: 700 }}
                        >
                            {metrics?.compliance?.csmr_ready ? "CSMR Reports Ready" : "CSMR Reports Processing"}
                        </Alert>
                    </Grid>
                </Grid>
            </Box>

            {/* Exception Queue Full-Width Section (Elevated directly under alerts) */}
            <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', mb: 6 }}>
                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                    <Box>
                        <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Exception Queue</Typography>
                        <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Unresolved operational and tax discrepancies</Typography>
                    </Box>
                    <ErrorIcon sx={{ color: 'error.main' }} />
                </Stack>

                <Grid container spacing={3}>
                    <Grid item xs={12} md={4}>
                        <Box 
                            onClick={() => window.location.href = '/transactions?status=FAILED'}
                            sx={{ 
                                p: 3, 
                                borderRadius: '20px', 
                                border: '1px solid rgba(239, 68, 68, 0.2)', 
                                bgcolor: 'rgba(239, 68, 68, 0.02)',
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                '&:hover': { bgcolor: 'rgba(239, 68, 68, 0.05)', transform: 'translateY(-2px)' }
                            }}
                        >
                            <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', mb: 1 }}>Failed Reconciliation</Typography>
                            <Stack direction="row" justifyContent="space-between" alignItems="baseline">
                                <Typography variant="h4" sx={{ fontWeight: 950, color: 'error.main' }}>
                                    {metrics?.exceptions?.failed_reconciliations || 0}
                                </Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'error.main', textTransform: 'uppercase' }}>Needs Review →</Typography>
                            </Stack>
                        </Box>
                    </Grid>

                    <Grid item xs={12} md={4}>
                        <Box 
                            onClick={() => window.location.href = '/system-logs'}
                            sx={{ 
                                p: 3, 
                                borderRadius: '20px', 
                                border: '1px solid rgba(245, 158, 11, 0.2)', 
                                bgcolor: 'rgba(245, 158, 11, 0.02)',
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                '&:hover': { bgcolor: 'rgba(245, 158, 11, 0.05)', transform: 'translateY(-2px)' }
                            }}
                        >
                            <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', mb: 1 }}>Missing Terminal Uploads</Typography>
                            <Stack direction="row" justifyContent="space-between" alignItems="baseline">
                                <Typography variant="h4" sx={{ fontWeight: 950, color: 'warning.main' }}>
                                    {metrics?.exceptions?.missing_uploads || 0}
                                </Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'warning.main', textTransform: 'uppercase' }}>Check Logs →</Typography>
                            </Stack>
                        </Box>
                    </Grid>

                    <Grid item xs={12} md={4}>
                        <Box 
                            onClick={() => window.location.href = '/transactions?status=FAILED'}
                            sx={{ 
                                p: 3, 
                                borderRadius: '20px', 
                                border: '1px solid rgba(239, 68, 68, 0.2)', 
                                bgcolor: 'rgba(239, 68, 68, 0.02)',
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                '&:hover': { bgcolor: 'rgba(239, 68, 68, 0.05)', transform: 'translateY(-2px)' }
                            }}
                        >
                            <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', mb: 1 }}>Invalid Tax Records</Typography>
                            <Stack direction="row" justifyContent="space-between" alignItems="baseline">
                                <Typography variant="h4" sx={{ fontWeight: 950, color: 'error.main' }}>
                                    {metrics?.exceptions?.invalid_tax_records || 0}
                                </Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'error.main', textTransform: 'uppercase' }}>Audit Maths →</Typography>
                            </Stack>
                        </Box>
                    </Grid>
                </Grid>
            </Box>

            {/* Charts Grid: Revenue Trend vs Revenue Breakdown */}
            <Grid container spacing={4} sx={{ mb: 6 }}>
                <Grid item xs={12} lg={7}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', position: 'relative', overflow: 'hidden' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Monthly Revenue Trend</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Sales and transactions lifecycle</Typography>
                            </Box>
                            <QueryStatsIcon sx={{ color: 'divider' }} />
                        </Stack>

                        {/* Visual summary info header */}
                        <Stack direction="row" spacing={4} sx={{ mb: 3, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.65rem' }}>Peak Revenue Day</Typography>
                                <Typography variant="body1" sx={{ fontWeight: 950, color: 'primary.main' }}>
                                    {chartStats.peakSalesDay} <Box component="span" sx={{ fontSize: '0.75rem', fontWeight: 700, color: 'text.secondary' }}>({formatCurrency(chartStats.peakSales)})</Box>
                                </Typography>
                            </Box>
                            <Box>
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.65rem' }}>Average Daily Revenue</Typography>
                                <Typography variant="body1" sx={{ fontWeight: 950, color: 'success.main' }}>
                                    {formatCurrency(chartStats.avgDailyRevenue)}
                                </Typography>
                            </Box>
                        </Stack>

                        <Box sx={{ h: 350, position: 'relative', zIndex: 1 }}>
                            <TransactionChart data={charts} loading={false} />
                        </Box>
                    </Box>
                </Grid>

                <Grid item xs={12} lg={5}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', height: '100%', display: 'flex', flexDirection: 'column' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Revenue Composition</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Tax Exempts, VAT, and discounts</Typography>
                            </Box>
                            <TimelineIcon sx={{ color: 'divider' }} />
                        </Stack>
                        <Box sx={{ flex: 1 }}>
                            <RevenueCompositionChart data={metrics?.revenue_composition} />
                        </Box>
                    </Box>
                </Grid>
            </Grid>

            {/* Reconciliation Status vs Compliance Status */}
            <Grid container spacing={4} sx={{ mb: 6 }}>
                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', height: '100%' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Reconciliation Status</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Processed vs unresolved transactions</Typography>
                            </Box>
                            <HubIcon sx={{ color: 'divider' }} />
                        </Stack>

                        <Grid container spacing={3} sx={{ mb: 4 }}>
                            <Grid item xs={4}>
                                <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 600 }}>Processed</Typography>
                                <Typography variant="h4" sx={{ fontWeight: 900, color: 'success.main' }}>
                                    {metrics?.reconciliation?.reconciled?.toLocaleString() || 0}
                                </Typography>
                            </Grid>
                            <Grid item xs={4}>
                                <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 600 }}>Pending</Typography>
                                <Typography variant="h4" sx={{ fontWeight: 900, color: 'warning.main' }}>
                                    {metrics?.reconciliation?.pending?.toLocaleString() || 0}
                                </Typography>
                            </Grid>
                            <Grid item xs={4}>
                                <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 600 }}>Failed</Typography>
                                <Typography variant="h4" sx={{ fontWeight: 900, color: 'error.main' }}>
                                    {metrics?.reconciliation?.failed?.toLocaleString() || 0}
                                </Typography>
                            </Grid>
                        </Grid>

                        <Divider sx={{ mb: 3 }} />

                        <Stack direction="row" justifyContent="space-between" alignItems="center">
                            <Box>
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, textTransform: 'uppercase' }}>Completion</Typography>
                                <Typography variant="h5" sx={{ fontWeight: 950 }}>{reconciledPercentage}</Typography>
                            </Box>
                            <Button 
                                variant="outlined" 
                                color="error" 
                                onClick={() => window.location.href = '/transactions?status=FAILED'}
                                sx={{ borderRadius: '12px', fontWeight: 800 }}
                            >
                                View Exceptions
                            </Button>
                        </Stack>
                    </Box>
                </Grid>

                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', height: '100%' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Compliance Status</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Submission readiness checklist</Typography>
                            </Box>
                            <CheckCircleIcon sx={{ color: 'divider' }} />
                        </Stack>

                        <List spacing={2}>
                            <ListItem sx={{ px: 0, py: 1.5, borderBottom: '1px solid rgba(229, 231, 245, 0.4)' }}>
                                <ListItemIcon>
                                    {metrics?.compliance?.csmr_ready ? <CheckCircleIcon sx={{ color: 'success.main' }} /> : <WarningIcon sx={{ color: 'warning.main' }} />}
                                </ListItemIcon>
                                <ListItemText 
                                    primary={<Typography sx={{ fontWeight: 800 }}>CSMR Report</Typography>} 
                                    secondary={<span>Certified Monthly Sales. Due: <b>{csmrDueDate}</b></span>} 
                                />
                                <Chip 
                                    label={metrics?.compliance?.csmr_ready ? "Ready" : "Pending Review"} 
                                    color={metrics?.compliance?.csmr_ready ? "success" : "warning"}
                                    size="small"
                                    sx={{ fontWeight: 800 }}
                                />
                            </ListItem>
                            <ListItem sx={{ px: 0, py: 1.5, borderBottom: '1px solid rgba(229, 231, 245, 0.4)' }}>
                                <ListItemIcon>
                                    {metrics?.compliance?.bir_export_generated ? <CheckCircleIcon sx={{ color: 'success.main' }} /> : <WarningIcon sx={{ color: 'warning.main' }} />}
                                </ListItemIcon>
                                <ListItemText 
                                    primary={<Typography sx={{ fontWeight: 800 }}>BIR Export</Typography>} 
                                    secondary={<span>Bureau of Internal Revenue. Run: <b>Today {metrics?.sync_status?.last_sync || '2:15 PM'}</b></span>} 
                                />
                                <Chip 
                                    label={metrics?.compliance?.bir_export_generated ? "Generated" : "Pending"} 
                                    color={metrics?.compliance?.bir_export_generated ? "success" : "warning"}
                                    size="small"
                                    sx={{ fontWeight: 800 }}
                                />
                            </ListItem>
                            <ListItem sx={{ px: 0, py: 1.5 }}>
                                <ListItemIcon>
                                    {metrics?.compliance?.tax_validation_passed ? <CheckCircleIcon sx={{ color: 'success.main' }} /> : <ErrorIcon sx={{ color: 'error.main' }} />}
                                </ListItemIcon>
                                <ListItemText 
                                    primary={<Typography sx={{ fontWeight: 800 }}>Tax Validation</Typography>} 
                                    secondary={<span>Math rules verification. Validated: <b>Today {metrics?.sync_status?.last_sync || '2:15 PM'}</b></span>} 
                                />
                                <Chip 
                                    label={metrics?.compliance?.tax_validation_passed ? "Passed" : "Failed"} 
                                    color={metrics?.compliance?.tax_validation_passed ? "success" : "error"}
                                    size="small"
                                    sx={{ fontWeight: 800 }}
                                />
                            </ListItem>
                        </List>
                    </Box>
                </Grid>
            </Grid>

            {/* Top Tenants vs Quick Actions */}
            <Grid container spacing={4}>
                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', height: '100%' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 3 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Top Tenants</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Tenants driving revenue</Typography>
                            </Box>
                            <BusinessIcon sx={{ color: 'divider' }} />
                        </Stack>

                        <TableContainer component={Paper} sx={{ boxShadow: 'none', background: 'transparent' }}>
                            <Table size="small">
                                <TableHead>
                                    <TableRow>
                                        <TableCell sx={{ fontWeight: 800, borderBottom: '2px solid rgba(229, 231, 245, 0.8)' }}>Rank</TableCell>
                                        <TableCell sx={{ fontWeight: 800, borderBottom: '2px solid rgba(229, 231, 245, 0.8)' }}>Tenant Name</TableCell>
                                        <TableCell align="right" sx={{ fontWeight: 800, borderBottom: '2px solid rgba(229, 231, 245, 0.8)' }}>Revenue</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {metrics?.top_tenants?.length > 0 ? (
                                        metrics.top_tenants.map((tenant, idx) => (
                                            <TableRow 
                                                key={idx} 
                                                onClick={() => window.location.href = `/transactions?search=${encodeURIComponent(tenant.trade_name)}`}
                                                sx={{ 
                                                    cursor: 'pointer',
                                                    '&:hover': { bgcolor: 'rgba(29, 67, 155, 0.03)' },
                                                    transition: 'background-color 0.15s ease'
                                                }}
                                            >
                                                <TableCell sx={{ fontWeight: 700, borderBottom: '1px solid rgba(229, 231, 245, 0.4)', py: 1.5 }}>
                                                    {idx + 1}
                                                </TableCell>
                                                <TableCell sx={{ fontWeight: 750, color: 'primary.main', borderBottom: '1px solid rgba(229, 231, 245, 0.4)', py: 1.5 }}>
                                                    {tenant.trade_name} <Box component="span" sx={{ fontSize: '0.65rem', fontWeight: 600, color: 'text.disabled', display: 'block' }}>View Logs →</Box>
                                                </TableCell>
                                                <TableCell align="right" sx={{ fontWeight: 800, color: 'primary.main', borderBottom: '1px solid rgba(229, 231, 245, 0.4)', py: 1.5 }}>
                                                    {formatCurrency(tenant.total_revenue)}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={3} align="center" sx={{ py: 3, color: 'text.secondary' }}>
                                                No tenant revenue records found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </TableContainer>
                    </Box>
                </Grid>

                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', height: '100%', display: 'flex', flexDirection: 'column' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Quick Actions Hub</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>On-demand financial exports</Typography>
                            </Box>
                            <GetAppIcon sx={{ color: 'divider' }} />
                        </Stack>

                        <Grid container spacing={3} sx={{ flex: 1, alignItems: 'center' }}>
                            <Grid item xs={12} sm={6}>
                                <Button 
                                    fullWidth 
                                    variant="outlined" 
                                    startIcon={<GetAppIcon />}
                                    onClick={() => handleExport('/finance/reports/export', { year: new Date().getFullYear(), month: new Date().getMonth() + 1 })}
                                    sx={{ borderRadius: '16px', py: 2, fontWeight: 800, borderStyle: 'dashed' }}
                                >
                                    Generate CSMR
                                </Button>
                            </Grid>
                            <Grid item xs={12} sm={6}>
                                <Button 
                                    fullWidth 
                                    variant="outlined" 
                                    startIcon={<GetAppIcon />}
                                    onClick={() => handleExport('/api/dashboard/export-transactions')}
                                    sx={{ borderRadius: '16px', py: 2, fontWeight: 800, borderStyle: 'dashed' }}
                                >
                                    Export Sales
                                </Button>
                            </Grid>
                            <Grid item xs={12} sm={6}>
                                <Button 
                                    fullWidth 
                                    variant="outlined" 
                                    startIcon={<GetAppIcon />}
                                    onClick={() => handleExport('/logs/export/csv')}
                                    sx={{ borderRadius: '16px', py: 2, fontWeight: 800, borderStyle: 'dashed' }}
                                >
                                    Export Reconciliation
                                </Button>
                            </Grid>
                            <Grid item xs={12} sm={6}>
                                <Button 
                                    fullWidth 
                                    variant="outlined" 
                                    startIcon={<GetAppIcon />}
                                    onClick={() => handleExport('/api/dashboard/export-audit-logs')}
                                    sx={{ borderRadius: '16px', py: 2, fontWeight: 800, borderStyle: 'dashed' }}
                                >
                                    Generate Audit Report
                                </Button>
                            </Grid>
                        </Grid>
                    </Box>
                </Grid>
            </Grid>
        </Box>
    );
};

export default FinanceDashboardPage;
