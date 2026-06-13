import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../services/api';
import MetricCard from '../Components/dashboard/MetricCard';
import TransactionChart from '../Components/dashboard/TransactionChart';
import RecentTransactionsTable from '../Components/dashboard/RecentTransactionsTable';
import SystemHealthMonitor from '../Components/dashboard/SystemHealthMonitor';
import RevenueByTerminalChart from '../Components/dashboard/RevenueByTerminalChart';
import NotificationToast from '../Components/dashboard/NotificationToast';
import TransactionDetailPanel from '../Components/transactions/TransactionDetailPanel';
import { useAuth } from '../Contexts/AuthContext';
import {
    Box,
    Typography,
    Button,
    FormControl,
    Select,
    MenuItem,
    Stack,
    CircularProgress,
    Divider,
    Alert,
    Card,
    CardContent,
    Grid,
    Tabs,
    Tab,
    Table,
    TableHead,
    TableBody,
    TableCell,
    TableRow,
    Chip,
    TextField,
    Tooltip
} from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';
import BarChartIcon from '@mui/icons-material/BarChart';
import SensorsIcon from '@mui/icons-material/Sensors';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import ListAltIcon from '@mui/icons-material/ListAlt';
import AccountBalanceWalletIcon from '@mui/icons-material/AccountBalanceWallet';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import DesktopWindowsIcon from '@mui/icons-material/DesktopWindows';
import ErrorIcon from '@mui/icons-material/Error';
import HourglassEmptyIcon from '@mui/icons-material/HourglassEmpty';
import { Breadcrumbs, Link as MuiLink } from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import DashboardIcon from '@mui/icons-material/Dashboard';

const currencyFormat = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);
const formatDateInput = (date) => date.toISOString().slice(0, 10);

const getPresetRange = (preset) => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    if (preset === 'today') {
        return { start: formatDateInput(today), end: formatDateInput(today) };
    }

    if (preset === 'yesterday') {
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        return { start: formatDateInput(yesterday), end: formatDateInput(yesterday) };
    }

    if (preset === '7days') {
        const start = new Date(today);
        start.setDate(start.getDate() - 6);
        return { start: formatDateInput(start), end: formatDateInput(today) };
    }

    if (preset === 'thismonth') {
        const start = new Date(today.getFullYear(), today.getMonth(), 1);
        return { start: formatDateInput(start), end: formatDateInput(today) };
    }

    return { start: '', end: '' };
};

const DashboardPage = () => {
    const { user } = useAuth();
    const [metrics, setMetrics] = useState(null);
    const [chartData, setChartData] = useState(null);
    const [health, setHealth] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedTransaction, setSelectedTransaction] = useState(null);
    const [detailPanelOpen, setDetailPanelOpen] = useState(false);
    const [refreshInterval, setRefreshInterval] = useState(30000); // 30 seconds for command center
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [notification, setNotification] = useState(null);
    const [alerts, setAlerts] = useState([]);
    const [lastUpdated, setLastUpdated] = useState(null);
    const [timeRange, setTimeRange] = useState('today');
    const [dashboardView, setDashboardView] = useState('operations');
    const [activityTab, setActivityTab] = useState('transactions');
    const [customRange, setCustomRange] = useState({ start: '', end: '' });
    const [filters, setFilters] = useState({
        start_date: getPresetRange('today').start,
        end_date: getPresetRange('today').end,
        terminal_id: '',
        search: ''
    });

    const userRoles = useMemo(() => user?.roles || [], [user]);
    const normalisedRoles = useMemo(() => userRoles.map(r => (typeof r === 'string' ? r : r?.name || '').toLowerCase()), [userRoles]);
    const hasRole = useCallback((allowedRoles) => allowedRoles.some(role => normalisedRoles.includes(role.toLowerCase())), [normalisedRoles]);

    const fetchDashboardData = useCallback(async (isInitial = false) => {
        try {
            if (isInitial) setLoading(true);
            setIsRefreshing(true);

            const [metricsRes, chartsRes, healthRes, tpRes, transactionsRes, auditRes, notificationsRes] = await Promise.all([
                api.getMetrics(),
                api.getCharts(),
                api.getSystemHealth(),
                api.getTerminalPerformance(),
                api.getTransactions(1, filters),
                api.getAuditLogs(1, filters),
                api.getNotifications()
            ]);

            setMetrics(metricsRes);
            setChartData(chartsRes);
            setHealth(healthRes);
            setTerminalPerformance(tpRes || []);
            setRecentTransactions(transactionsRes.data || []);
            setAuditLogs(auditRes.data || []);
            setAlerts(notificationsRes.data || []);
            setLastUpdated(new Date());

            if (healthRes && healthRes.cpu > 85) {
                setNotification({ message: 'Critical high CPU usage detected! System performance may be affected.', type: 'error' });
            } else if (isInitial) {
                setNotification({ message: 'Dashboard Command Center is active and monitoring live terminals.', type: 'success' });
            }
        } catch (error) {
            console.error('Error fetching dashboard data:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchDashboardData(true);
    }, [fetchDashboardData]);

    useEffect(() => {
        if (refreshInterval <= 0) return;
        const timer = setInterval(() => fetchDashboardData(), refreshInterval);
        return () => clearInterval(timer);
    }, [fetchDashboardData, refreshInterval]);

    useEffect(() => {
        if (timeRange === 'custom') {
            return;
        }

        const preset = getPresetRange(timeRange);
        setFilters((prev) => ({ ...prev, start_date: preset.start, end_date: preset.end }));
    }, [timeRange]);

    const handleApplyCustomRange = useCallback(() => {
        if (!customRange.start || !customRange.end) {
            return;
        }

        setFilters((prev) => ({
            ...prev,
            start_date: customRange.start,
            end_date: customRange.end
        }));
    }, [customRange]);

    const handleViewDetails = useCallback((transaction) => {
        setSelectedTransaction(transaction);
        setDetailPanelOpen(true);
    }, []);

    const handleRefresh = useCallback(() => {
        fetchDashboardData();
    }, [fetchDashboardData]);

    const handleDismissAlert = useCallback(async (id) => {
        try {
            await api.dismissNotification(id);
            setAlerts((prev) => prev.filter((n) => n.id !== id));
        } catch (err) {
            console.error('Failed to dismiss alert', err);
        }
    }, []);

    const openTransactions = useCallback((extraParams = {}) => {
        const params = new URLSearchParams({
            ...(filters.start_date ? { date_from: filters.start_date } : {}),
            ...(filters.end_date ? { date_to: filters.end_date } : {}),
            ...extraParams
        });
        window.location.href = `/transactions?${params.toString()}`;
    }, [filters.end_date, filters.start_date]);

    // Operational helpers
    const activeTerminals = Number(metrics?.active_terminals?.current ?? 0);
    const totalTerminals = Number(metrics?.active_terminals?.total ?? 0);
    const offlineTerminals = Math.max(totalTerminals - activeTerminals, 0);

    const reconciled = Number(metrics?.reconciliation?.reconciled ?? metrics?.reconciled_transactions?.current ?? 0);
    const reconciliationTotal = Number(metrics?.reconciliation?.total ?? metrics?.total_transactions?.current ?? 0);
    const failedReconciliation = Number(metrics?.reconciliation?.failed ?? 0);

    const pendingUploads = Number(metrics?.pending_uploads?.current ?? health?.queues?.backlog ?? 0);
    const queueBacklog = Number(health?.queues?.backlog ?? 0);

    // Memoized KPI transforms
    const kpiData = useMemo(() => {
        const rev = metrics?.total_sales ? currencyFormat(metrics.total_sales.current) : '₱0.00';
        const revTrend = metrics?.total_sales?.trend;
        const revSparkline = metrics?.total_sales?.sparkline;

        const txCount = metrics?.total_transactions?.current ?? 0;
        const txTrend = metrics?.total_transactions?.trend;
        const txSparkline = metrics?.total_transactions?.sparkline;

        // Total exceptions sum is mutually exclusive
        const totalExceptions = Number(metrics?.exceptions?.total_exceptions ?? 0);

        return {
            revenue: { value: rev, trend: revTrend, sparkline: revSparkline },
            transactions: { value: txCount, trend: txTrend, sparkline: txSparkline },
            exceptions: { value: totalExceptions },
            pendingUploads: { value: pendingUploads },
            offlineTerminals: { value: offlineTerminals, total: totalTerminals }
        };
    }, [metrics, pendingUploads, offlineTerminals, totalTerminals]);

    // Memoized Alert severity groupings
    const groupedAlerts = useMemo(() => {
        const critical = [];
        const warning = [];
        const advisory = [];

        alerts.forEach((alert) => {
            const payload = alert.data || {};
            const severityRaw = String(payload.severity || 'info').toLowerCase();
            
            if (severityRaw === 'high' || severityRaw === 'error' || severityRaw === 'critical') {
                critical.push(alert);
            } else if (severityRaw === 'medium' || severityRaw === 'warning') {
                warning.push(alert);
            } else {
                advisory.push(alert);
            }
        });

        return { critical, warning, advisory };
    }, [alerts]);

    // Memoized Escalation counts
    const escalationCounts = useMemo(() => {
        const criticalCount = groupedAlerts.critical.length + failedReconciliation + (offlineTerminals > 0 ? 1 : 0);
        const warningCount = groupedAlerts.warning.length + (pendingUploads > 0 ? 1 : 0);
        const advisoryCount = groupedAlerts.advisory.length;
        return { criticalCount, warningCount, advisoryCount };
    }, [groupedAlerts, failedReconciliation, offlineTerminals, pendingUploads]);

    // Memoized Ingestion Success Rate (reconciled / (reconciled + exceptions))
    const ingestionSuccessRate = useMemo(() => {
        const reconciledVal = Number(metrics?.reconciliation?.reconciled ?? 0);
        const exceptionsVal = Number(metrics?.exceptions?.total_exceptions ?? 0);
        const total = reconciledVal + exceptionsVal;
        if (total === 0) return '100.00';
        return ((reconciledVal / total) * 100).toFixed(2);
    }, [metrics]);

    // Memoized Heatmap data mapping
    const heatmapData = useMemo(() => {
        if (loading) return { loading: true, data: [] };
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
            return { empty: true, data: [] };
        }

        const isHourly = chartData.labels.some(label => {
            const labelStr = String(label);
            return labelStr.includes(':') || /^\d{1,2}$/.test(labelStr);
        });

        if (!isHourly) {
            return { empty: true, data: [] };
        }

        const dataPoints = chartData.labels.map((label, idx) => {
            let hourStr = String(label);
            if (/^\d+$/.test(hourStr)) {
                hourStr = `${hourStr.padStart(2, '0')}:00`;
            }
            const volume = Number(chartData.volume?.[idx] ?? 0);
            return { hour: hourStr, volume };
        });

        return { data: dataPoints };
    }, [chartData, loading]);

    // Audit logs filters for tabs
    const exceptionRows = useMemo(() => {
        return (auditLogs || []).filter((entry) => {
            const combined = `${entry?.level || ''} ${entry?.action || ''} ${entry?.message || ''}`.toLowerCase();
            return combined.includes('error') || combined.includes('fail') || combined.includes('exception') || combined.includes('warning');
        });
    }, [auditLogs]);

    const reconciliationRows = useMemo(() => {
        return (auditLogs || []).filter((entry) => {
            const combined = `${entry?.action || ''} ${entry?.message || ''} ${entry?.event || ''}`.toLowerCase();
            return combined.includes('reconcil');
        });
    }, [auditLogs]);

    // Alert action router renderer
    const renderAlertRow = (alert, type) => {
        const payload = alert.data || {};
        const title = payload.title || 'System Alert';
        const message = payload.message || title;
        
        let route = null;
        let btnText = 'Investigate';
        let requiredRoles = ['admin', 'manager'];

        const combined = `${title} ${message}`.toLowerCase();
        if (combined.includes('reconcil')) {
            route = '/transactions?status=failed_reconciliation';
            btnText = 'Resolve Reconciliations';
            requiredRoles = ['admin', 'manager', 'finance'];
        } else if (combined.includes('upload') || combined.includes('intake') || combined.includes('payload')) {
            route = '/payload-sandbox?status=pending';
            btnText = 'Review Upload Queue';
            requiredRoles = ['admin', 'manager'];
        } else if (combined.includes('terminal') || combined.includes('offline') || combined.includes('hardware')) {
            route = '/terminal-tokens?status=offline';
            btnText = 'Open Terminal Management';
            requiredRoles = ['admin', 'commercial'];
        }

        const canAccess = hasRole(requiredRoles);

        return (
            <Alert
                key={alert.id}
                severity={type}
                onClose={() => handleDismissAlert(alert.id)}
                action={
                    route && (
                        <Button
                            size="small"
                            color="inherit"
                            disabled={!canAccess}
                            onClick={() => window.location.href = route}
                            sx={{
                                fontWeight: 800,
                                textTransform: 'none',
                                textDecoration: 'underline',
                                '&:hover': { bgcolor: 'rgba(0,0,0,0.05)' }
                            }}
                        >
                            {canAccess ? btnText : 'Access Restricted'}
                        </Button>
                    )
                }
                sx={{ mb: 1 }}
            >
                <strong>{title}: </strong>
                {message}
            </Alert>
        );
    };

    return (
        <Box sx={{ pb: 10 }}>
            {/* Unified Breadcrumbs */}
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>DASHBOARD COMMAND</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', lg: 'center' }} sx={{ mb: 6 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <DashboardIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Operations Command Center
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Live telemetry, diagnostic monitoring, and device health status.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction="row" alignItems="center" spacing={2} flexWrap="wrap" useFlexGap>
                        {/* Real-time sync details info bar */}
                        <Stack direction="row" spacing={2} alignItems="center" sx={{ bgcolor: 'rgba(0,0,0,0.03)', px: 2, py: 1, borderRadius: 3, border: '1px solid', borderColor: 'divider' }}>
                            <Box>
                                <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                    LAST UPDATED
                                </Typography>
                                <Typography variant="body2" sx={{ fontWeight: 900, color: 'text.primary' }}>
                                    {lastUpdated ? lastUpdated.toLocaleTimeString() : 'Not synced'}
                                </Typography>
                            </Box>
                            <Divider orientation="vertical" flexItem />
                            <Box>
                                <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                    AUTO REFRESH
                                </Typography>
                                <Typography variant="body2" sx={{ fontWeight: 900, color: refreshInterval > 0 ? 'success.main' : 'text.secondary' }}>
                                    {refreshInterval > 0 ? `Every ${refreshInterval / 1000}s` : 'OFF'}
                                </Typography>
                            </Box>
                        </Stack>

                        <FormControl variant="outlined" size="small">
                            <Select
                                value={dashboardView}
                                onChange={(e) => setDashboardView(e.target.value)}
                                sx={{
                                    bgcolor: 'white',
                                    minWidth: 180,
                                    borderRadius: 3,
                                    fontWeight: 'bold',
                                    color: 'primary.main'
                                }}
                            >
                                <MenuItem value="executive">Executive View</MenuItem>
                                <MenuItem value="operations">Operations View</MenuItem>
                                <MenuItem value="audit">Audit View</MenuItem>
                            </Select>
                        </FormControl>
                        <Button
                            variant="outlined"
                            color="inherit"
                            onClick={handleRefresh}
                            disabled={isRefreshing}
                            startIcon={isRefreshing ? <CircularProgress size={20} color="inherit" /> : <RefreshIcon />}
                            sx={{
                                borderRadius: 3,
                                px: 2.5,
                                py: 1.2,
                                fontWeight: 700,
                                borderColor: 'divider',
                                textTransform: 'none',
                                bgcolor: 'white',
                                boxShadow: '0 4px 12px rgba(0,0,0,0.03)',
                                '&:hover': { bgcolor: 'grey.50', borderColor: 'grey.300' }
                            }}
                        >
                            {isRefreshing ? 'Refreshing...' : 'Sync Data'}
                        </Button>
                        <Button
                            variant="outlined"
                            color={refreshInterval > 0 ? 'success' : 'inherit'}
                            onClick={() => setRefreshInterval((prev) => (prev > 0 ? 0 : 30000))}
                            sx={{ borderRadius: 3, textTransform: 'none', fontWeight: 700, px: 2 }}
                        >
                            Auto Refresh: {refreshInterval > 0 ? 'ON' : 'OFF'}
                        </Button>
                        <FormControl variant="outlined" size="small">
                            <Select
                                id="time-range-select"
                                value={timeRange}
                                onChange={(e) => setTimeRange(e.target.value)}
                                sx={{
                                    bgcolor: 'white',
                                    minWidth: 180,
                                    borderRadius: 3,
                                    fontWeight: 'bold',
                                    color: 'primary.main',
                                    boxShadow: '0 4px 12px rgba(0,0,0,0.03)',
                                    '& .MuiOutlinedInput-notchedOutline': { borderColor: 'divider' },
                                    '&:hover .MuiOutlinedInput-notchedOutline': { borderColor: 'primary.main' }
                                }}
                            >
                                <MenuItem value="today">Today</MenuItem>
                                <MenuItem value="yesterday">Yesterday</MenuItem>
                                <MenuItem value="7days">Last 7 Days</MenuItem>
                                <MenuItem value="thismonth">This Month</MenuItem>
                                <MenuItem value="custom">Custom</MenuItem>
                            </Select>
                        </FormControl>
                    </Stack>
                </Stack>

                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'flex-start', md: 'center' }} sx={{ mb: 3 }}>
                    {timeRange === 'custom' && (
                        <>
                            <TextField
                                size="small"
                                type="date"
                                label="From"
                                InputLabelProps={{ shrink: true }}
                                value={customRange.start}
                                onChange={(e) => setCustomRange((prev) => ({ ...prev, start: e.target.value }))}
                            />
                            <TextField
                                size="small"
                                type="date"
                                label="To"
                                InputLabelProps={{ shrink: true }}
                                value={customRange.end}
                                onChange={(e) => setCustomRange((prev) => ({ ...prev, end: e.target.value }))}
                            />
                            <Button variant="contained" onClick={handleApplyCustomRange} sx={{ textTransform: 'none', fontWeight: 700 }}>
                                Apply Range
                            </Button>
                        </>
                    )}
                </Stack>
            </Box>

            {/* Layout Block 1: Key Performance Indicators */}
            <Box sx={{ mb: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <BarChartIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    Key Performance Indicators
                </Typography>
                <Typography variant="body2" sx={{ color: 'text.secondary', mb: 3, fontWeight: 500 }}>
                    Today vs yesterday, based on transaction timestamps.
                </Typography>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6">
                    <Tooltip title="Total gross transaction sales value for the period.">
                        <div>
                            <MetricCard
                                title="Revenue"
                                value={kpiData.revenue.value}
                                trend={kpiData.revenue.trend}
                                sparkline={kpiData.revenue.sparkline}
                                subtitle="vs yesterday"
                                onClick={() => openTransactions()}
                                icon={<AccountBalanceWalletIcon />}
                                color="primary"
                            />
                        </div>
                    </Tooltip>
                    
                    <Tooltip title="Total quantity of transaction logs submitted.">
                        <div>
                            <MetricCard
                                title="Transactions"
                                value={kpiData.transactions.value}
                                trend={kpiData.transactions.trend}
                                sparkline={kpiData.transactions.sparkline}
                                subtitle="vs yesterday"
                                onClick={() => openTransactions()}
                                icon={<ReceiptLongIcon />}
                                color="accent"
                            />
                        </div>
                    </Tooltip>

                    <Tooltip title="Sum of failed reconciliations, missing uploads, and invalid tax records. Mutual exclusion guarantees no double-counting.">
                        <div>
                            <MetricCard
                                title="Total Exceptions"
                                value={kpiData.exceptions.value}
                                subtitle="Unmatched or error logs"
                                onClick={() => {
                                    setActivityTab('exceptions');
                                    const el = document.getElementById('activity-logs-section');
                                    if (el) el.scrollIntoView({ behavior: 'smooth' });
                                }}
                                icon={<ErrorIcon />}
                                color="danger"
                            />
                        </div>
                    </Tooltip>

                    <Tooltip title="Intake queue payloads pending ingestion.">
                        <div>
                            <MetricCard
                                title="Pending Uploads"
                                value={kpiData.pendingUploads.value}
                                subtitle="Queued ingestion tasks"
                                onClick={() => {
                                    if (hasRole(['admin', 'manager'])) {
                                        window.location.href = '/payload-sandbox?status=pending';
                                    }
                                }}
                                icon={<HourglassEmptyIcon />}
                                color="accent"
                            />
                        </div>
                    </Tooltip>

                    <Tooltip title="Terminals not uploading data within 1 hour inactivity threshold.">
                        <div>
                            <MetricCard
                                title="Offline Terminals"
                                value={`${kpiData.offlineTerminals.value} / ${kpiData.offlineTerminals.total}`}
                                subtitle={`${kpiData.offlineTerminals.value} offline`}
                                onClick={() => {
                                    if (hasRole(['admin', 'commercial'])) {
                                        window.location.href = '/terminal-tokens?status=offline';
                                    }
                                }}
                                icon={<DesktopWindowsIcon />}
                                color="danger"
                            />
                        </div>
                    </Tooltip>
                </div>
            </Box>

            {/* Layout Block 2: Operational Alerts & Escalation Panel */}
            {(dashboardView === 'operations' || dashboardView === 'audit') && (
                <Box sx={{ mb: 10 }}>
                    <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 3, textTransform: 'uppercase' }}>
                        <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                        Operational Alerts & Escalation Queue
                    </Typography>
                    
                    <Grid container spacing={4}>
                        {/* Alerts Box */}
                        <Grid item xs={12} lg={8}>
                            <Card sx={{ height: '100%', borderRadius: 3, border: '1px solid', borderColor: 'divider' }}>
                                <CardContent>
                                    <Grid container spacing={2} sx={{ mb: 3 }}>
                                        <Grid item xs={12} md={3}>
                                            <Tooltip title="Reconciliation jobs failing validation rules.">
                                                <Chip color={failedReconciliation > 0 ? 'error' : 'success'} label={`${failedReconciliation} Failed Reconciliations`} sx={{ fontWeight: 800, width: '100%' }} />
                                            </Tooltip>
                                        </Grid>
                                        <Grid item xs={12} md={3}>
                                            <Tooltip title="Submitted intake payloads awaiting queue execution.">
                                                <Chip color={pendingUploads > 0 ? 'warning' : 'success'} label={`${pendingUploads} Pending Ingestions`} sx={{ fontWeight: 800, width: '100%' }} />
                                            </Tooltip>
                                        </Grid>
                                        <Grid item xs={12} md={3}>
                                            <Tooltip title="Terminals not uploading data within 1 hour inactivity threshold.">
                                                <Chip color={offlineTerminals > 0 ? 'error' : 'success'} label={`${offlineTerminals} Offline Terminals`} sx={{ fontWeight: 800, width: '100%' }} />
                                            </Tooltip>
                                        </Grid>
                                        <Grid item xs={12} md={3}>
                                            <Tooltip title="Active queue backlog count.">
                                                <Chip color={queueBacklog > 20 ? 'warning' : 'success'} label={queueBacklog > 20 ? 'Queue Busy' : 'Queue Healthy'} sx={{ fontWeight: 800, width: '100%' }} />
                                            </Tooltip>
                                        </Grid>
                                    </Grid>

                                    <Stack spacing={2}>
                                        {groupedAlerts.critical.length > 0 && (
                                            <Box>
                                                <Typography variant="subtitle2" color="error.main" sx={{ fontWeight: 900, mb: 1 }}>
                                                    🔴 Critical ({groupedAlerts.critical.length})
                                                </Typography>
                                                <Stack spacing={1}>
                                                    {groupedAlerts.critical.map((alert) => renderAlertRow(alert, 'error'))}
                                                </Stack>
                                            </Box>
                                        )}

                                        {groupedAlerts.warning.length > 0 && (
                                            <Box>
                                                <Typography variant="subtitle2" color="warning.main" sx={{ fontWeight: 900, mb: 1 }}>
                                                    Orange Warning ({groupedAlerts.warning.length})
                                                </Typography>
                                                <Stack spacing={1}>
                                                    {groupedAlerts.warning.map((alert) => renderAlertRow(alert, 'warning'))}
                                                </Stack>
                                            </Box>
                                        )}

                                        {groupedAlerts.advisory.length > 0 && (
                                            <Box>
                                                <Typography variant="subtitle2" color="info.main" sx={{ fontWeight: 900, mb: 1 }}>
                                                    Yellow Advisory ({groupedAlerts.advisory.length})
                                                </Typography>
                                                <Stack spacing={1}>
                                                    {groupedAlerts.advisory.map((alert) => renderAlertRow(alert, 'info'))}
                                                </Stack>
                                            </Box>
                                        )}

                                        {alerts.length === 0 && (
                                            <Alert severity="success">No active critical alerts.</Alert>
                                        )}
                                    </Stack>
                                </CardContent>
                            </Card>
                        </Grid>

                        {/* Escalation Widget */}
                        <Grid item xs={12} lg={4}>
                            <Card sx={{ height: '100%', borderRadius: 3, border: '1px solid', borderColor: 'divider', bgcolor: 'rgba(255, 255, 255, 0.4)' }}>
                                <CardContent sx={{ p: 4 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 3, color: 'text.primary', letterSpacing: '-0.02em' }}>
                                        Open Operational Issues
                                    </Typography>
                                    <Stack spacing={3}>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', p: 2, bgcolor: escalationCounts.criticalCount > 0 ? 'rgba(211, 47, 47, 0.08)' : 'rgba(0,0,0,0.02)', borderRadius: 3, border: '1px solid', borderColor: escalationCounts.criticalCount > 0 ? 'error.light' : 'divider' }}>
                                            <Stack direction="row" spacing={1.5} alignItems="center">
                                                <Typography sx={{ fontSize: 20 }}>🔴</Typography>
                                                <Box>
                                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Critical Issues</Typography>
                                                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>Requires immediate intervention</Typography>
                                                </Box>
                                            </Stack>
                                            <Typography variant="h4" sx={{ fontWeight: 950, color: escalationCounts.criticalCount > 0 ? 'error.main' : 'text.secondary' }}>
                                                {escalationCounts.criticalCount}
                                            </Typography>
                                        </Box>

                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', p: 2, bgcolor: escalationCounts.warningCount > 0 ? 'rgba(237, 108, 2, 0.08)' : 'rgba(0,0,0,0.02)', borderRadius: 3, border: '1px solid', borderColor: escalationCounts.warningCount > 0 ? 'warning.light' : 'divider' }}>
                                            <Stack direction="row" spacing={1.5} alignItems="center">
                                                <Typography sx={{ fontSize: 20 }}>🟠</Typography>
                                                <Box>
                                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Warnings</Typography>
                                                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>Operational warnings</Typography>
                                                </Box>
                                            </Stack>
                                            <Typography variant="h4" sx={{ fontWeight: 950, color: escalationCounts.warningCount > 0 ? 'warning.main' : 'text.secondary' }}>
                                                {escalationCounts.warningCount}
                                            </Typography>
                                        </Box>

                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', p: 2, bgcolor: 'rgba(0,0,0,0.02)', borderRadius: 3, border: '1px solid', borderColor: 'divider' }}>
                                            <Stack direction="row" spacing={1.5} alignItems="center">
                                                <Typography sx={{ fontSize: 20 }}>🟡</Typography>
                                                <Box>
                                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Advisories</Typography>
                                                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>General alerts</Typography>
                                                </Box>
                                            </Stack>
                                            <Typography variant="h4" sx={{ fontWeight: 950, color: 'text.secondary' }}>
                                                {escalationCounts.advisoryCount}
                                            </Typography>
                                        </Box>
                                    </Stack>
                                </CardContent>
                            </Card>
                        </Grid>
                    </Grid>
                </Box>
            )}

            {/* Layout Block 3: Operational Performance & Heatmap */}
            {(dashboardView === 'executive' || dashboardView === 'operations') && (
                <Box sx={{ mb: 10 }}>
                    <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                        <TrendingUpIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                        Operational Performance
                    </Typography>
                    
                    <Grid container spacing={4}>
                        {/* Metrics and Chart */}
                        <Grid item xs={12} lg={8} sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <Grid container spacing={2}>
                                <Grid item xs={12} sm={6} md={3}>
                                    <Tooltip title="Active terminals uploading data vs total enrolled devices. Devices inactive for 1 hour are marked offline.">
                                        <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                                            <CardContent sx={{ p: 2 }}>
                                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', display: 'block', mb: 0.5 }}>
                                                    Terminals Online
                                                </Typography>
                                                <Typography variant="h6" sx={{ fontWeight: 900 }}>
                                                    {activeTerminals} / {totalTerminals}
                                                </Typography>
                                                <Typography variant="body2" sx={{ color: 'text.secondary', fontSize: '0.72rem' }}>
                                                    {offlineTerminals} offline
                                                </Typography>
                                            </CardContent>
                                        </Card>
                                    </Tooltip>
                                </Grid>
                                
                                <Grid item xs={12} sm={6} md={3}>
                                    <Tooltip title="Registered tenants with transaction activity in selected period vs total registered tenants.">
                                        <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                                            <CardContent sx={{ p: 2 }}>
                                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', display: 'block', mb: 0.5 }}>
                                                    Tenants With Activity / Total Tenants
                                                </Typography>
                                                <Typography variant="h6" sx={{ fontWeight: 900 }}>
                                                    {metrics?.active_tenants?.current ?? 0} / {metrics?.active_tenants?.total ?? 0}
                                                </Typography>
                                                <Typography variant="body2" sx={{ color: 'text.secondary', fontSize: '0.72rem' }}>
                                                    Active in period
                                                </Typography>
                                            </CardContent>
                                        </Card>
                                    </Tooltip>
                                </Grid>

                                <Grid item xs={12} sm={6} md={3}>
                                    <Tooltip title="Success rate is calculated as reconciled transactions divided by total ingestion submissions (reconciled + exceptions).">
                                        <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                                            <CardContent sx={{ p: 2 }}>
                                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', display: 'block', mb: 0.5 }}>
                                                    Ingestion Success Rate
                                                </Typography>
                                                <Typography variant="h6" sx={{ fontWeight: 900, color: Number(ingestionSuccessRate) >= 99 ? 'success.main' : 'warning.main' }}>
                                                    {ingestionSuccessRate}%
                                                </Typography>
                                                <Typography variant="body2" sx={{ color: 'text.secondary', fontSize: '0.72rem' }}>
                                                    Successful ingestions
                                                </Typography>
                                            </CardContent>
                                        </Card>
                                    </Tooltip>
                                </Grid>

                                <Grid item xs={12} sm={6} md={3}>
                                    <Tooltip title="Backlog queue jobs waiting in redis/database pipelines.">
                                        <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                                            <CardContent sx={{ p: 2 }}>
                                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', display: 'block', mb: 0.5 }}>
                                                    Queue Backlog
                                                </Typography>
                                                <Typography variant="h6" sx={{ fontWeight: 900, color: queueBacklog > 5 ? 'error.main' : 'text.primary' }}>
                                                    {queueBacklog}
                                                </Typography>
                                                <Typography variant="body2" sx={{ color: 'text.secondary', fontSize: '0.72rem' }}>
                                                    Pending execution
                                                </Typography>
                                            </CardContent>
                                        </Card>
                                    </Tooltip>
                                </Grid>
                            </Grid>

                            <TransactionChart data={chartData} loading={loading} />
                        </Grid>

                        {/* Terminal Activity Heatmap */}
                        <Grid item xs={12} lg={4}>
                            <Card sx={{ height: '100%', borderRadius: 3, border: '1px solid', borderColor: 'divider' }}>
                                <CardContent sx={{ p: 4, display: 'flex', flexDirection: 'column', height: '100%' }}>
                                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 1, color: 'primary.main' }}>
                                        Terminal Activity Heatmap
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', mb: 4, fontWeight: 700 }}>
                                        Hourly transaction activity distribution. Hover block to inspect volume.
                                    </Typography>

                                    <Box sx={{ flex: 1, minHeight: 0 }}>
                                        {heatmapData.empty ? (
                                            <Box sx={{ height: '100%', minHeight: 250, display: 'flex', alignItems: 'center', justifyContent: 'center', border: '1px dashed', borderColor: 'divider', borderRadius: 3, p: 2, textAlign: 'center' }}>
                                                <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 600 }}>
                                                    No hourly activity available for selected period
                                                </Typography>
                                            </Box>
                                        ) : heatmapData.loading ? (
                                            <Box sx={{ height: '100%', minHeight: 250, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                                <CircularProgress size={24} />
                                            </Box>
                                        ) : (
                                            <Grid container spacing={1.5}>
                                                {heatmapData.data.map((item, idx) => {
                                                    const maxVol = Math.max(...heatmapData.data.map(d => d.volume), 1);
                                                    const density = item.volume / maxVol;
                                                    const bgcolor = density === 0 ? 'rgba(0,0,0,0.02)' : `hsla(210, 85%, ${90 - (density * 55)}%, ${0.3 + density * 0.7})`;
                                                    const textColor = density > 0.5 ? '#fff' : 'text.primary';

                                                    return (
                                                        <Grid item xs={4} key={idx}>
                                                            <Tooltip title={`${item.volume} transactions at ${item.hour}`}>
                                                                <Box
                                                                    sx={{
                                                                        bgcolor,
                                                                        color: textColor,
                                                                        py: 1.5,
                                                                        px: 1,
                                                                        borderRadius: 2,
                                                                        textAlign: 'center',
                                                                        border: '1px solid',
                                                                        borderColor: density === 0 ? 'divider' : 'transparent',
                                                                        transition: 'all 0.2s',
                                                                        '&:hover': { transform: 'scale(1.05)', boxShadow: '0 4px 12px rgba(29, 67, 155, 0.15)' }
                                                                    }}
                                                                >
                                                                    <Typography variant="caption" sx={{ fontWeight: 800, display: 'block', fontSize: '0.65rem' }}>
                                                                        {item.hour}
                                                                    </Typography>
                                                                    <Typography variant="body2" sx={{ fontWeight: 900, fontSize: '0.85rem' }}>
                                                                        {item.volume}
                                                                    </Typography>
                                                                </Box>
                                                            </Tooltip>
                                                        </Grid>
                                                    );
                                                })}
                                            </Grid>
                                        )}
                                    </Box>
                                </CardContent>
                            </Card>
                        </Grid>
                    </Grid>
                </Box>
            )}

            {/* Layout Block 4: Terminal Performance */}
            {(dashboardView === 'executive' || dashboardView === 'operations') && (
                <Box sx={{ mb: 10 }}>
                    <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                        <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                        Terminal Performance
                    </Typography>
                    <RevenueByTerminalChart data={terminalPerformance} loading={loading} />
                </Box>
            )}

            {/* Layout Block 5: Detailed Activity */}
            <Box id="activity-logs-section" sx={{ mt: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <ListAltIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    Recent Activity Logs
                </Typography>
                <Box sx={{ bgcolor: 'white', borderRadius: '2rem', shadow: '0 20px 40px rgba(0,0,0,0.05)', border: '1px solid', borderColor: 'grey.100', overflow: 'hidden' }}>
                    <Tabs
                        value={activityTab}
                        onChange={(_, value) => setActivityTab(value)}
                        sx={{ px: 2, borderBottom: '1px solid', borderColor: 'divider' }}
                    >
                        <Tab value="transactions" label="Transactions" />
                        <Tab value="exceptions" label={`Exceptions (${exceptionRows.length})`} />
                        <Tab value="reconciliation" label={`Reconciliation (${reconciliationRows.length})`} />
                    </Tabs>

                    {activityTab === 'transactions' && (
                        <RecentTransactionsTable
                            transactions={recentTransactions}
                            loading={loading}
                            onForward={handleViewDetails}
                        />
                    )}

                    {(activityTab === 'exceptions' || activityTab === 'reconciliation') && (
                        <Box sx={{ p: 3 }}>
                            <Table size="small">
                                <TableHead>
                                    <TableRow>
                                        <TableCell sx={{ fontWeight: 800 }}>Type</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Severity</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Source</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Description</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Timestamp</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {(activityTab === 'exceptions' ? exceptionRows : reconciliationRows).slice(0, 15).map((entry) => {
                                        const actionText = entry.action || entry.event || 'System';
                                        let severityLabel = 'Info';
                                        let severityColor = 'info';
                                        
                                        const combinedStr = `${entry?.level || ''} ${entry?.action || ''} ${entry?.message || ''}`.toLowerCase();
                                        if (combinedStr.includes('error') || combinedStr.includes('fail') || combinedStr.includes('critical')) {
                                            severityLabel = 'Critical';
                                            severityColor = 'error';
                                        } else if (combinedStr.includes('warning') || combinedStr.includes('pending')) {
                                            severityLabel = 'Warning';
                                            severityColor = 'warning';
                                        }

                                        return (
                                            <TableRow key={`activity-${entry.id || Math.random()}`}>
                                                <TableCell sx={{ fontWeight: 700 }}>{actionText}</TableCell>
                                                <TableCell>
                                                    <Chip size="small" label={severityLabel} color={severityColor} sx={{ fontWeight: 800 }} />
                                                </TableCell>
                                                <TableCell>{entry.user?.name || entry.user_name || entry.actor || 'System'}</TableCell>
                                                <TableCell>{entry.message || entry.description || '-'}</TableCell>
                                                <TableCell>{entry.created_at || entry.timestamp || '-'}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                    {(activityTab === 'exceptions' ? exceptionRows : reconciliationRows).length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} align="center" sx={{ color: 'text.secondary' }}>
                                                No records found for this activity stream.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </Box>
                    )}
                </Box>
            </Box>

            {/* Layout Block 6: System Hardware Health */}
            {(dashboardView === 'operations' || dashboardView === 'audit') && (
                <Box sx={{ mt: 10 }}>
                    <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                        <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                        System Status & Health
                    </Typography>
                    <SystemHealthMonitor health={health} loading={loading} />
                </Box>
            )}

            <TransactionDetailPanel
                open={detailPanelOpen}
                transaction={selectedTransaction}
                onClose={() => setDetailPanelOpen(false)}
            />

            {notification && (
                <NotificationToast
                    message={notification.message}
                    type={notification.type}
                    onClose={() => setNotification(null)}
                />
            )}
        </Box>
    );
};

export default DashboardPage;
