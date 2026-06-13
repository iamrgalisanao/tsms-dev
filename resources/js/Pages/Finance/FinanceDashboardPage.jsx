import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
    Box,
    Typography,
    Grid,
    Button,
    CircularProgress,
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
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import InfoIcon from '@mui/icons-material/Info';

import TransactionChart from '../../Components/dashboard/TransactionChart';
import axios from 'axios';

// Custom Premium Metric Card Component
const CustomMetricCard = ({ title, value, subtitle, trend, trendDirection, trendColor, icon, gradient, onClick }) => {
    return (
        <Box
            onClick={onClick}
            sx={{
                p: 3,
                borderRadius: '24px',
                background: gradient || 'rgba(255, 255, 255, 0.7)',
                backdropFilter: 'blur(20px)',
                border: '1px solid rgba(255, 255, 255, 0.4)',
                boxShadow: '0 8px 30px rgba(0, 0, 0, 0.04)',
                transition: 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s ease',
                cursor: onClick ? 'pointer' : 'default',
                '&:hover': {
                    transform: onClick || trend ? 'translateY(-6px)' : 'none',
                    boxShadow: onClick || trend ? '0 20px 35px rgba(29, 67, 155, 0.08)' : '0 8px 30px rgba(0, 0, 0, 0.04)',
                },
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                color: gradient ? 'white' : 'text.primary',
                height: '100%',
                boxSizing: 'border-box'
            }}
        >
            <Box>
                <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', opacity: gradient ? 0.8 : 0.6, display: 'block', mb: 1 }}>
                    {title}
                </Typography>
                <Typography variant="h4" sx={{ fontWeight: 950, tracking: '-0.02em', mb: 0.5 }}>
                    {value}
                </Typography>
                <Stack direction="row" spacing={1} alignItems="center">
                    {trend && (
                        <Stack direction="row" alignItems="center" spacing={0.25} sx={{ 
                            color: trendColor || 'success.main', 
                            bgcolor: gradient ? 'rgba(255,255,255,0.2)' : (trendDirection === 'down' ? 'error.light' : 'success.light'), 
                            px: 1, 
                            py: 0.25, 
                            borderRadius: '8px',
                            fontWeight: 900,
                            fontSize: '0.65rem'
                        }}>
                            {trendDirection === 'down' ? <ArrowDownwardIcon sx={{ fontSize: 10 }} /> : <ArrowUpwardIcon sx={{ fontSize: 10 }} />}
                            <span>{trend}%</span>
                        </Stack>
                    )}
                    <Typography variant="caption" sx={{ fontWeight: 600, opacity: gradient ? 0.9 : 0.7 }}>
                        {subtitle}
                    </Typography>
                </Stack>
            </Box>
            <Box sx={{ 
                p: 1.5, 
                borderRadius: '16px', 
                bgcolor: gradient ? 'rgba(255, 255, 255, 0.2)' : 'rgba(29, 67, 155, 0.05)', 
                color: gradient ? 'white' : 'primary.main', 
                display: 'flex',
                boxShadow: gradient ? 'none' : 'inset 0 2px 4px rgba(0,0,0,0.02)'
            }}>
                {icon}
            </Box>
        </Box>
    );
};

// Custom SVG Donut Chart Component
const RevenueCompositionChart = ({ data }) => {
    const categories = useMemo(() => [
        { name: 'Net Sales', value: data?.net_sales || 0, color: '#10B981' },
        { name: 'VAT', value: data?.vat || 0, color: '#F59E0B' },
        { name: 'Tax Exempt', value: data?.tax_exempt || 0, color: '#3B82F6' },
        { name: 'Refunds', value: data?.refunds || 0, color: '#EF4444' },
        { name: 'Discounts', value: data?.discounts || 0, color: '#8B5CF6' },
    ], [data]);

    const total = useMemo(() => categories.reduce((sum, c) => sum + c.value, 0), [categories]);
    const r = 50;
    const circ = 2 * Math.PI * r;

    let accumulatedPercent = 0;

    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);

    return (
        <Box sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row' }, alignItems: 'center', gap: 4, height: '100%', py: 2 }}>
            <Box sx={{ position: 'relative', width: 180, height: 180, display: 'flex', justifyContent: 'center', alignItems: 'center', flexShrink: 0 }}>
                <svg width="100%" height="100%" viewBox="0 0 120 120" style={{ transform: 'rotate(-90deg)' }}>
                    <circle cx="60" cy="60" r={r} fill="transparent" stroke="rgba(229, 231, 245, 0.5)" strokeWidth="12" />
                    {total > 0 && categories.map((cat, idx) => {
                        if (cat.value <= 0) return null;
                        const pct = (cat.value / total) * 100;
                        const strokeDashoffset = circ - (pct * circ) / 100;
                        const strokeDasharray = circ;
                        const rotation = (accumulatedPercent / 100) * 360;
                        accumulatedPercent += pct;
                        return (
                            <circle
                                key={idx}
                                cx="60"
                                cy="60"
                                r={r}
                                fill="transparent"
                                stroke={cat.color}
                                strokeWidth="12"
                                strokeDasharray={strokeDasharray}
                                strokeDashoffset={strokeDashoffset}
                                style={{
                                    transformOrigin: '60px 60px',
                                    transform: `rotate(${rotation}deg)`,
                                    transition: 'stroke-dashoffset 0.5s ease',
                                }}
                            />
                        );
                    })}
                </svg>
                <Box sx={{ position: 'absolute', textAlign: 'center', width: 120 }}>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                        Total Revenue
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 950, color: 'text.primary', fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {formatCurrency(total)}
                    </Typography>
                </Box>
            </Box>
            <Stack spacing={1.5} sx={{ flex: 1, width: '100%' }}>
                {categories.map((cat, idx) => {
                    const pct = total > 0 ? ((cat.value / total) * 100).toFixed(1) : 0;
                    return (
                        <Box key={idx} sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid rgba(229, 231, 245, 0.4)', pb: 0.75 }}>
                            <Stack direction="row" spacing={1.5} alignItems="center">
                                <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: cat.color }} />
                                <Typography variant="body2" sx={{ fontWeight: 750, color: 'text.primary' }}>{cat.name}</Typography>
                            </Stack>
                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.secondary' }}>
                                {formatCurrency(cat.value)} <Box component="span" sx={{ color: 'text.disabled', fontWeight: 600, fontSize: '0.75rem', ml: 0.5 }}>({pct}%)</Box>
                            </Typography>
                        </Box>
                    );
                })}
            </Stack>
        </Box>
    );
};

const FinanceDashboardPage = () => {
    const [metrics, setMetrics] = useState(null);
    const [charts, setCharts] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [dateRange, setDateRange] = useState('7'); // '7' or '30' days

    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);

    const fetchData = useCallback(async (days = dateRange) => {
        setRefreshing(true);
        try {
            const today = new Date();
            const start = new Date();
            start.setDate(today.getDate() - (parseInt(days) - 1));

            const params = {
                start_date: start.toISOString().split('T')[0],
                end_date: today.toISOString().split('T')[0]
            };

            const [metricsResp, chartsResp] = await Promise.all([
                axios.get('/api/dashboard/metrics', { params }),
                axios.get('/api/dashboard/charts', { params: { days } })
            ]);

            setMetrics(metricsResp.data);
            setCharts(chartsResp.data);
        } catch (error) {
            console.error('Error fetching finance metrics:', error);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [dateRange]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleForceSync = useCallback(() => {
        fetchData();
    }, [fetchData]);

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

    if (loading) {
        return (
            <Box sx={{ display: 'flex', height: '80vh', alignItems: 'center', justifyContent: 'center' }}>
                <CircularProgress color="primary" size={50} />
            </Box>
        );
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

                    <Stack direction="row" spacing={2} alignItems="center" sx={{ width: { xs: '100%', sm: 'auto' } }}>
                        {/* Date Range Selector */}
                        <FormControl size="small" sx={{ minWidth: 140 }}>
                            <InputLabel id="date-range-label">Period</InputLabel>
                            <Select
                                labelId="date-range-label"
                                value={dateRange}
                                label="Period"
                                onChange={(e) => {
                                    setDateRange(e.target.value);
                                    fetchData(e.target.value);
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
                            disabled={refreshing}
                            startIcon={refreshing ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />}
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
                            {refreshing ? 'Syncing...' : 'Force Sync'}
                        </Button>
                    </Stack>
                </Stack>
            </Box>

            {/* KPI Cards Row */}
            <Grid container spacing={4} sx={{ mb: 6 }}>
                <Grid item xs={12} sm={6} lg={3}>
                    <CustomMetricCard
                        title="Gross Sales"
                        value={formatCurrency(metrics?.total_sales?.current || 0)}
                        subtitle="Aggregated sales value"
                        trend={metrics?.total_sales?.trend}
                        trendDirection={metrics?.total_sales?.trend < 0 ? 'down' : 'up'}
                        trendColor={metrics?.total_sales?.trend < 0 ? 'error.main' : 'success.main'}
                        icon={<TrendingUpIcon sx={{ fontSize: 24 }} />}
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <CustomMetricCard
                        title="Net Sales"
                        value={formatCurrency(metrics?.total_net_sales?.current || 0)}
                        subtitle="Revenue net of tax/exempts"
                        trend={metrics?.total_net_sales?.trend}
                        trendDirection={metrics?.total_net_sales?.trend < 0 ? 'down' : 'up'}
                        trendColor={metrics?.total_net_sales?.trend < 0 ? 'error.main' : 'success.main'}
                        icon={<AnalyticsIcon sx={{ fontSize: 24 }} />}
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <CustomMetricCard
                        title="Reconciled"
                        value={`${metrics?.reconciliation?.reconciled?.toLocaleString() || 0} / ${metrics?.reconciliation?.total?.toLocaleString() || 0}`}
                        subtitle="Reconciliation completion rate"
                        trend={parseFloat(reconciledPercentage)}
                        trendDirection={parseFloat(reconciledPercentage) < 99.5 ? 'down' : 'up'}
                        trendColor={parseFloat(reconciledPercentage) < 99.5 ? 'warning.main' : 'success.main'}
                        icon={<CheckCircleIcon sx={{ fontSize: 24 }} />}
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <CustomMetricCard
                        title="Exceptions"
                        value={metrics?.exceptions?.total_exceptions || 0}
                        subtitle="Unresolved exceptions"
                        trend={null}
                        gradient={metrics?.exceptions?.total_exceptions > 0 ? 'linear-gradient(135deg, #EF4444 0%, #DC2626 100%)' : null}
                        icon={<ErrorIcon sx={{ fontSize: 24 }} />}
                        onClick={() => window.location.href = `/transactions?status=FAILED`}
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

            {/* Charts Grid: Revenue Trend vs Revenue Breakdown */}
            <Grid container spacing={4} sx={{ mb: 6 }}>
                <Grid item xs={12} lg={7}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', position: 'relative', overflow: 'hidden' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Monthly Revenue Trend</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Sales and transactions lifecycle</Typography>
                            </Box>
                            <QueryStatsIcon sx={{ color: 'divider' }} />
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
                                    secondary="Certified Monthly Sales Report status" 
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
                                    secondary="Bureau of Internal Revenue export files" 
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
                                    secondary="VAT and exemption math validations" 
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
            <Grid container spacing={4} sx={{ mb: 6 }}>
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
                                            <TableRow key={idx}>
                                                <TableCell sx={{ fontWeight: 700, borderBottom: '1px solid rgba(229, 231, 245, 0.4)', py: 1.5 }}>
                                                    {idx + 1}
                                                </TableCell>
                                                <TableCell sx={{ fontWeight: 700, borderBottom: '1px solid rgba(229, 231, 245, 0.4)', py: 1.5 }}>
                                                    {tenant.trade_name}
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

            {/* Exception Queue Full-Width Section */}
            <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)' }}>
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

                <Divider sx={{ my: 3 }} />

                {/* Ecosystem Sync Footer Status */}
                <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems="center" spacing={2}>
                    <Stack direction="row" spacing={1.5} alignItems="center">
                        <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'success.main', boxShadow: '0 0 10px #10B981' }} />
                        <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                            Ecosystem Status: {metrics?.sync_status?.status || 'Healthy'}
                        </Typography>
                    </Stack>
                    <Stack direction="row" spacing={3}>
                        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                            Records Synced: <Box component="span" sx={{ fontWeight: 900, color: 'text.primary' }}>{metrics?.sync_status?.records_synced?.toLocaleString() || 0}</Box>
                        </Typography>
                        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                            Last Sync: <Box component="span" sx={{ fontWeight: 900, color: 'text.primary' }}>{metrics?.sync_status?.last_sync || 'N/A'}</Box>
                        </Typography>
                    </Stack>
                </Stack>
            </Box>
        </Box>
    );
};

export default FinanceDashboardPage;
