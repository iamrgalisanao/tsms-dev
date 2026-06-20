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
    Tooltip,
    useTheme
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
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

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

const LoadingPanel = ({ label = 'Loading page details...' }) => (
    <Box sx={{
        minHeight: 180,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 1.5,
        color: '#64748b'
    }}>
        <CircularProgress size={34} thickness={4} sx={{ color: '#e11d2d' }} />
        <Typography sx={{ fontSize: '0.72rem', fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.12em' }}>
            {label}
        </Typography>
    </Box>
);

const DashboardPage = () => {
    const { user } = useAuth();
    const [metrics, setMetrics] = useState(null);
    const [chartData, setChartData] = useState(null);
    const [health, setHealth] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadingSections, setLoadingSections] = useState({
        metrics: true,
        charts: true,
        health: true,
        terminalPerformance: true,
        transactions: true,
        auditLogs: true,
        notifications: true
    });
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

    const runDashboardRequest = useCallback(async (section, request, onSuccess, showLoading = true) => {
        if (showLoading) {
            setLoadingSections((prev) => ({ ...prev, [section]: true }));
        }

        try {
            const response = await request();
            onSuccess(response);
            return response;
        } catch (error) {
            console.error(`Error fetching dashboard ${section}:`, error);
            return null;
        } finally {
            if (showLoading) {
                setLoadingSections((prev) => ({ ...prev, [section]: false }));
            }
        }
    }, []);

    const fetchDashboardData = useCallback(async (isInitial = false) => {
        if (isInitial) {
            setLoading(true);
        }

        setIsRefreshing(true);

        try {
            const [metricsRes, , healthRes] = await Promise.all([
                runDashboardRequest('metrics', () => api.getMetrics(), setMetrics, isInitial),
                runDashboardRequest('charts', () => api.getCharts(), setChartData, isInitial),
                runDashboardRequest('health', () => api.getSystemHealth(), setHealth, isInitial),
                runDashboardRequest('terminalPerformance', () => api.getTerminalPerformance(), (response) => setTerminalPerformance(response || []), isInitial),
                runDashboardRequest('transactions', () => api.getTransactions(1, { ...filters, per_page: 10 }), (response) => setRecentTransactions(response?.data || []), isInitial),
                runDashboardRequest('auditLogs', () => api.getAuditLogs(1, { ...filters, per_page: 10 }), (response) => setAuditLogs(response?.data || []), isInitial),
                runDashboardRequest('notifications', () => api.getNotifications(), (response) => setAlerts(response?.data || []), isInitial)
            ]);
            setLastUpdated(new Date());

            if (healthRes && healthRes.cpu > 85) {
                setNotification({ message: 'Critical high CPU usage detected! System performance may be affected.', type: 'error' });
            } else if (isInitial && metricsRes) {
                setNotification({ message: 'Dashboard Command Center is active and monitoring live terminals.', type: 'success' });
            }
        } catch (error) {
            console.error('Error fetching dashboard data:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, [filters, runDashboardRequest]);

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

    const kpiValue = useCallback((value) => (
        loadingSections.metrics ? <CircularProgress size={26} sx={{ color: '#e11d2d' }} /> : value
    ), [loadingSections.metrics]);

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
        const accentColor = type === 'error' ? '#dc2626' : type === 'warning' ? '#d97706' : '#2563eb';
        const borderColor = type === 'error' ? '#fecaca' : type === 'warning' ? '#fde68a' : '#bfdbfe';

        return (
            <Box
                key={alert.id}
                sx={{
                    p: 2,
                    mb: 1.5,
                    bgcolor: 'white',
                    border: '1px solid #f1f5f9', // slate-100
                    borderRadius: '12px',
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: 2,
                    transition: 'all 0.2s',
                    '&:hover': {
                        borderColor: borderColor,
                    }
                }}
            >
                {/* Status Dot */}
                <Box sx={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    bgcolor: accentColor,
                    mt: 0.75,
                    flexShrink: 0,
                    position: 'relative',
                    ...(type === 'error' && {
                        '&::after': {
                            content: '""',
                            position: 'absolute',
                            width: '100%',
                            height: '100%',
                            bgcolor: 'inherit',
                            borderRadius: '50%',
                            animation: 'pulseAlert 2s infinite',
                        },
                        '@keyframes pulseAlert': {
                            '0%': { transform: 'scale(1)', opacity: 0.8 },
                            '100%': { transform: 'scale(3)', opacity: 0 }
                        }
                    })
                }} />

                <Box sx={{ flex: 1 }}>
                    <Typography variant="body2" sx={{ fontWeight: 800, color: '#0f172a', mb: 0.5 }}>
                        {title}
                    </Typography>
                    <Typography variant="caption" sx={{ color: '#64748b', display: 'block', mb: 1.5 }}>
                        {message}
                    </Typography>
                    {route && (
                        <Button
                            size="small"
                            disabled={!canAccess}
                            onClick={() => window.location.href = route}
                            sx={{
                                p: 0,
                                minWidth: 'auto',
                                fontWeight: 800,
                                textTransform: 'none',
                                fontSize: '0.75rem',
                                color: type === 'error' ? '#e11d2d' : accentColor,
                                '&:hover': { color: '#0a1931', bgcolor: 'transparent' }
                            }}
                        >
                            {canAccess ? `${btnText} →` : 'Access Restricted'}
                        </Button>
                    )}
                </Box>
                <Button
                    size="small"
                    color="inherit"
                    onClick={() => handleDismissAlert(alert.id)}
                    sx={{
                        minWidth: 'auto',
                        p: 0.5,
                        ml: 1,
                        borderRadius: '50%',
                        color: '#94a3b8',
                        '&:hover': { color: '#0f172a', bgcolor: '#f1f5f9' }
                    }}
                >
                    ✕
                </Button>
            </Box>
        );
    };

    const theme = useTheme();
    return (
        <Box sx={{
            minHeight: '100vh',
            width: '100%',
            boxSizing: 'border-box',
            overflowX: 'hidden',
            position: 'relative',
            bgcolor: '#F8FAFC',
            backgroundImage: `linear-gradient(to right, rgba(29, 67, 155, 0.035) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(29, 67, 155, 0.035) 1px, transparent 1px)`,
            backgroundSize: '24px 24px',
        }}>
            {/* Top App Bar */}
            <Box component="header" sx={{
                position: 'sticky',
                top: 0,
                zIndex: 50,
                bgcolor: 'rgba(255, 255, 255, 0.95)',
                backdropFilter: 'blur(12px)',
                borderBottom: '1px solid #e2e8f0',
                px: { xs: 3, md: 5, lg: 8 },
                py: 2.5,
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: { xs: 'wrap', md: 'nowrap' },
                gap: 2
            }}>
                {/* Left side: Breadcrumbs and Title */}
                <Box sx={{ display: 'flex', flexDirection: 'column' }}>
                    <Breadcrumbs
                        separator={<NavigateNextIcon sx={{ fontSize: 10, opacity: 0.5 }} />}
                        sx={{ mb: 1, '& .MuiTypography-root': { fontWeight: 800, fontSize: '0.625rem', letterSpacing: '0.1em', textTransform: 'uppercase', color: '#94a3b8' } }}
                    >
                        <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center' }}>
                            <HomeIcon sx={{ mr: 0.5, fontSize: 12 }} />
                            SYSTEM
                        </MuiLink>
                        <Typography sx={{ fontWeight: 800, color: '#475569' }}>DASHBOARD COMMAND</Typography>
                    </Breadcrumbs>
                    
                    <Stack direction="row" spacing={2} alignItems="center">
                        <Box sx={{
                            width: 44,
                            height: 44,
                            bgcolor: '#0a1931',
                            color: 'white',
                            borderRadius: '12px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center'
                        }}>
                            <DashboardIcon sx={{ fontSize: 24 }} />
                        </Box>
                        <Box>
                            <Stack direction="row" spacing={1.5} alignItems="center">
                                <Typography variant="h4" sx={{ fontWeight: 900, color: '#0f172a', letterSpacing: '-0.02em', fontSize: '1.5rem', fontFamily: '"Hanken Grotesk", sans-serif' }}>
                                    Operations Command Center
                                </Typography>
                                <Tooltip title="Live system connection active" arrow>
                                    <Box sx={{
                                        width: 8,
                                        height: 8,
                                        borderRadius: '50%',
                                        bgcolor: '#22c55e',
                                        position: 'relative',
                                        '&::after': {
                                            content: '""',
                                            position: 'absolute',
                                            width: '100%',
                                            height: '100%',
                                            bgcolor: 'inherit',
                                            borderRadius: '50%',
                                            animation: 'pulseGreen 2s infinite',
                                        },
                                        '@keyframes pulseGreen': {
                                            '0%': { transform: 'scale(1)', opacity: 0.8 },
                                            '100%': { transform: 'scale(3)', opacity: 0 }
                                        }
                                    }} />
                                </Tooltip>
                            </Stack>
                            <Typography variant="body2" sx={{ color: '#64748b', fontSize: '0.75rem', fontWeight: 500, mt: 0.25 }}>
                                Live telemetry, diagnostic monitoring, and device health status.
                            </Typography>
                        </Box>
                    </Stack>
                </Box>

                {/* Right side controls toolbar */}
                <Stack direction="row" alignItems="center" spacing={2} flexWrap="wrap">
                    {/* Real-time telemetry info bar */}
                    <Box sx={{ display: { xs: 'none', lg: 'flex' }, alignItems: 'center', gap: 3, borderRight: '1px solid #e2e8f0', pr: 3 }}>
                        <Box>
                            <Typography sx={{ color: '#94a3b8', display: 'block', fontWeight: 700, textTransform: 'uppercase', fontSize: '0.55rem', letterSpacing: '0.05em' }}>
                                LAST UPDATED
                            </Typography>
                            <Typography sx={{ fontWeight: 700, color: '#334155', fontSize: '0.875rem', fontFamily: '"JetBrains Mono", monospace' }}>
                                {lastUpdated ? lastUpdated.toLocaleTimeString() : 'Not synced'}
                            </Typography>
                        </Box>
                        <Box>
                            <Typography sx={{ color: '#94a3b8', display: 'block', fontWeight: 700, textTransform: 'uppercase', fontSize: '0.55rem', letterSpacing: '0.05em' }}>
                                REFRESH
                            </Typography>
                            <Typography sx={{ fontWeight: 700, color: refreshInterval > 0 ? '#16a34a' : '#94a3b8', fontSize: '0.875rem', fontFamily: '"JetBrains Mono", monospace' }}>
                                {refreshInterval > 0 ? `${refreshInterval / 1000}s` : 'OFF'}
                            </Typography>
                        </Box>
                    </Box>

                    <Button
                        variant="contained"
                        color="inherit"
                        onClick={handleRefresh}
                        disabled={isRefreshing}
                        startIcon={isRefreshing ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon sx={{ fontSize: 18 }} />}
                        sx={{
                            borderRadius: '8px',
                            px: 2,
                            py: 1,
                            fontWeight: 700,
                            fontSize: '0.75rem',
                            textTransform: 'none',
                            bgcolor: '#f1f5f9',
                            color: '#334155',
                            boxShadow: 'none',
                            '&:hover': { bgcolor: '#e2e8f0', boxShadow: 'none' }
                        }}
                    >
                        {isRefreshing ? 'Syncing...' : 'Sync Data'}
                    </Button>

                    <FormControl variant="outlined" size="small">
                        <Select
                            value={timeRange}
                            onChange={(e) => setTimeRange(e.target.value)}
                            sx={{
                                bgcolor: 'white',
                                borderRadius: '8px',
                                fontWeight: 700,
                                fontSize: '0.75rem',
                                color: '#334155',
                                height: '36px',
                                '& .MuiOutlinedInput-notchedOutline': { borderColor: '#e2e8f0' },
                                '&:hover .MuiOutlinedInput-notchedOutline': { borderColor: '#cbd5e1' }
                            }}
                        >
                            <MenuItem value="today">Today</MenuItem>
                            <MenuItem value="yesterday">Yesterday</MenuItem>
                            <MenuItem value="7days">Last 7 Days</MenuItem>
                            <MenuItem value="thismonth">This Month</MenuItem>
                            <MenuItem value="custom">Custom</MenuItem>
                        </Select>
                    </FormControl>

                    <FormControl variant="outlined" size="small">
                        <Select
                            value={dashboardView}
                            onChange={(e) => setDashboardView(e.target.value)}
                            sx={{
                                bgcolor: 'white',
                                borderRadius: '8px',
                                fontWeight: 700,
                                fontSize: '0.75rem',
                                color: '#334155',
                                height: '36px',
                                '& .MuiOutlinedInput-notchedOutline': { borderColor: '#e2e8f0' },
                                '&:hover .MuiOutlinedInput-notchedOutline': { borderColor: '#cbd5e1' }
                            }}
                        >
                            <MenuItem value="executive">Executive</MenuItem>
                            <MenuItem value="operations">Operations</MenuItem>
                            <MenuItem value="audit">Audit</MenuItem>
                        </Select>
                    </FormControl>
                </Stack>
            </Box>

            {/* Custom date range selector below header if active */}
            {timeRange === 'custom' && (
                <Box sx={{ px: { xs: 3, md: 5, lg: 8 }, py: 2, bgcolor: 'white', borderBottom: '1px solid #e2e8f0' }}>
                    <Stack direction="row" spacing={2} alignItems="center">
                        <TextField
                            size="small"
                            type="date"
                            label="From"
                            InputLabelProps={{ shrink: true }}
                            value={customRange.start}
                            onChange={(e) => setCustomRange((prev) => ({ ...prev, start: e.target.value }))}
                            sx={{ '& .MuiOutlinedInput-root': { borderRadius: '8px' } }}
                        />
                        <TextField
                            size="small"
                            type="date"
                            label="To"
                            InputLabelProps={{ shrink: true }}
                            value={customRange.end}
                            onChange={(e) => setCustomRange((prev) => ({ ...prev, end: e.target.value }))}
                            sx={{ '& .MuiOutlinedInput-root': { borderRadius: '8px' } }}
                        />
                        <Button
                            variant="contained"
                            onClick={handleApplyCustomRange}
                            sx={{ textTransform: 'none', fontWeight: 800, borderRadius: '8px', px: 3 }}
                        >
                            Apply Range
                        </Button>
                    </Stack>
                </Box>
            )}

                        {/* Dashboard Content - Branded Edition */}
            <div className="p-8 space-y-8 w-full mx-auto">
                {/* Embedded Styles from Stitch */}
                <style>{`
                    .elite-card {
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
                        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                    }
                    .elite-card:hover {
                        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
                        border-color: #cbd5e1;
                    }
                    .tabular-nums {
                        font-variant-numeric: tabular-nums;
                    }
                `}</style>

                {/* KPI Section */}
                <section>
                    <div className="flex items-center gap-2 mb-6">
                        <span className="material-symbols-outlined text-blue-600">analytics</span>
                        <h2 className="text-xs font-bold text-blue-600 uppercase tracking-widest">Key Performance Indicators</h2>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
                        {/* Revenue Card */}
                        <div className="elite-card rounded-2xl p-5 flex flex-col justify-between">
                            <div className="flex justify-between items-start mb-4">
                                <div className="w-10 h-10 bg-cyan-100 rounded-xl flex items-center justify-center text-cyan-600">
                                    <span className="material-symbols-outlined">payments</span>
                                </div>
                                {!loadingSections.metrics && <span className="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-1 rounded">↑ {kpiData.revenue.trend}</span>}
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Revenue</p>
                                <h3 className="text-3xl font-display font-black text-slate-900 tabular-nums min-h-[40px] flex items-center">{kpiValue(kpiData.revenue.value)}</h3>
                                <p className="text-[10px] text-slate-400 mt-2">vs yesterday</p>
                            </div>
                        </div>

                        {/* Transactions Card */}
                        <div className="elite-card rounded-2xl p-5 flex flex-col justify-between">
                            <div className="flex justify-between items-start mb-4">
                                <div className="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center text-amber-600">
                                    <span className="material-symbols-outlined">receipt_long</span>
                                </div>
                                {!loadingSections.metrics && <span className="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-1 rounded">↑ {kpiData.transactions.trend}</span>}
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Transactions</p>
                                <h3 className="text-3xl font-display font-black text-slate-900 tabular-nums min-h-[40px] flex items-center">{kpiValue(kpiData.transactions.value)}</h3>
                                <p className="text-[10px] text-slate-400 mt-2">vs yesterday</p>
                            </div>
                        </div>

                        {/* Exceptions Card */}
                        <div className="elite-card rounded-2xl p-5 flex flex-col justify-between">
                            <div className="flex justify-between items-start mb-4">
                                <div className="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center text-[#e11d2d]">
                                    <span className="material-symbols-outlined">error</span>
                                </div>
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Exceptions</p>
                                <h3 className="text-3xl font-display font-black text-slate-900 tabular-nums min-h-[40px] flex items-center">{kpiValue(kpiData.exceptions.value)}</h3>
                                <p className="text-[10px] text-slate-400 mt-2">Unmatched error logs</p>
                            </div>
                        </div>

                        {/* Pending Uploads Card */}
                        <div className="elite-card rounded-2xl p-5 flex flex-col justify-between">
                            <div className="flex justify-between items-start mb-4">
                                <div className="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center text-indigo-600">
                                    <span className="material-symbols-outlined">hourglass_top</span>
                                </div>
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Pending Uploads</p>
                                <h3 className="text-3xl font-display font-black text-slate-900 tabular-nums min-h-[40px] flex items-center">{kpiValue(kpiData.pendingUploads.value)}</h3>
                                <p className="text-[10px] text-slate-400 mt-2">Queued ingestion tasks</p>
                            </div>
                        </div>

                        {/* Offline Terminals Card */}
                        <div className="elite-card rounded-2xl p-5 flex flex-col justify-between border-[#e11d2d]/20">
                            <div className="flex justify-between items-start mb-4">
                                <div className="w-10 h-10 bg-[#e11d2d]/10 rounded-xl flex items-center justify-center text-[#e11d2d]">
                                    <span className="material-symbols-outlined">router</span>
                                </div>
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Offline Terminals</p>
                                <h3 className="text-3xl font-display font-black text-slate-900 tabular-nums min-h-[40px] flex items-center">{kpiValue(<>{kpiData.offlineTerminals.total - kpiData.offlineTerminals.value} <span className="text-lg text-slate-300 font-medium">/ {kpiData.offlineTerminals.total}</span></>)}</h3>
                                <p className="text-[10px] text-[#e11d2d] font-bold mt-2">{kpiData.offlineTerminals.value} offline</p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Alerts & Issues Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    {/* Escalation Queue */}
                    <div className="lg:col-span-2 elite-card rounded-2xl flex flex-col overflow-hidden">
                        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                            <div className="flex items-center gap-2">
                                <span className="material-symbols-outlined text-blue-600 text-lg">sensors</span>
                                <h3 className="text-xs font-bold text-slate-600 uppercase tracking-widest">Operational Alerts & Escalation Queue</h3>
                            </div>
                            <div className="flex gap-2">
                                <span className={`px-2 py-1 text-[9px] font-bold rounded uppercase ${queueBacklog > 20 ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700'}`}>
                                    {queueBacklog > 20 ? 'Queue Busy' : 'Queue Healthy'}
                                </span>
                            </div>
                        </div>
                        <div className="flex-1 flex flex-col items-center justify-center p-12 text-center h-[350px]">
                            {loadingSections.notifications ? (
                                <LoadingPanel label="Loading alert details..." />
                            ) : alerts.length === 0 ? (
                                <>
                                    <div className="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mb-6">
                                        <span className="material-symbols-outlined text-green-600 text-[40px]" style={{ fontVariationSettings: "'FILL' 1" }}>check_circle</span>
                                    </div>
                                    <h4 className="text-xl font-bold text-slate-900 mb-2">All Systems Nominal</h4>
                                    <p className="text-sm text-slate-500 max-w-sm">No active critical alerts or terminal queue exceptions detected in the current environment.</p>
                                </>
                            ) : (
                                <Box sx={{ width: '100%', height: '100%', overflowY: 'auto', p: 2 }}>
                                    <Stack spacing={1}>
                                        {groupedAlerts.critical.map((alert) => renderAlertRow(alert, 'error'))}
                                        {groupedAlerts.warning.map((alert) => renderAlertRow(alert, 'warning'))}
                                        {groupedAlerts.advisory.map((alert) => renderAlertRow(alert, 'info'))}
                                    </Stack>
                                </Box>
                            )}
                        </div>
                    </div>

                    {/* Open Issues Panel */}
                    <div className="elite-card rounded-2xl flex flex-col">
                        <div className="px-6 py-4 border-b border-slate-100">
                            <h3 className="font-bold text-slate-900">Open Operations Issues</h3>
                        </div>
                        <div className="p-6 space-y-4 flex-1">
                            <div className="flex items-center justify-between p-4 bg-white border border-slate-100 rounded-xl hover:border-red-200 transition-colors group cursor-pointer">
                                <div className="flex items-center gap-4">
                                    <div className="w-3 h-3 rounded-full bg-[#ef4444]" style={{boxShadow: escalationCounts.criticalCount > 0 ? '0 0 8px #ef4444' : 'none'}}></div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-900">Critical Issues</p>
                                        <p className="text-[10px] text-slate-400 uppercase">Action Required</p>
                                    </div>
                                </div>
                                <span className="text-2xl font-black text-slate-900">{loading ? <CircularProgress size={18} sx={{ color: '#e11d2d' }} /> : escalationCounts.criticalCount}</span>
                            </div>
                            
                            <div className="flex items-center justify-between p-4 bg-white border border-slate-100 rounded-xl hover:border-orange-200 transition-colors group cursor-pointer">
                                <div className="flex items-center gap-4">
                                    <div className="w-3 h-3 rounded-full bg-[#f97316]"></div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-900">Warnings</p>
                                        <p className="text-[10px] text-slate-400 uppercase">Operational Exceptions</p>
                                    </div>
                                </div>
                                <span className="text-2xl font-black text-slate-900">{loading ? <CircularProgress size={18} sx={{ color: '#e11d2d' }} /> : escalationCounts.warningCount}</span>
                            </div>
                            
                            <div className="flex items-center justify-between p-4 bg-white border border-slate-100 rounded-xl hover:border-yellow-200 transition-colors group cursor-pointer">
                                <div className="flex items-center gap-4">
                                    <div className="w-3 h-3 rounded-full bg-[#facc15]"></div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-900">Advisories</p>
                                        <p className="text-[10px] text-slate-400 uppercase">Maintenance</p>
                                    </div>
                                </div>
                                <span className="text-2xl font-black text-slate-900">{loading ? <CircularProgress size={18} sx={{ color: '#e11d2d' }} /> : escalationCounts.advisoryCount}</span>
                            </div>
                        </div>
                        <div className="p-6 pt-0">
                            <button className="w-full py-2.5 text-xs font-bold text-slate-500 hover:text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors border border-slate-200 uppercase tracking-widest">
                                View Queue Archive
                            </button>
                        </div>
                    </div>
                </div>

                {/* Performance Analytics */}
                <section className="grid grid-cols-1 lg:grid-cols-4 gap-8">
                    {/* Secondary Stats Row */}
                    <div className="lg:col-span-4 grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div className="elite-card p-4 rounded-xl flex items-center justify-between">
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Terminals Online</p>
                                <p className="text-xl font-black text-slate-900">{kpiData.offlineTerminals.total - kpiData.offlineTerminals.value} / {kpiData.offlineTerminals.total}</p>
                            </div>
                            <span className="material-symbols-outlined text-blue-600 bg-blue-50 p-2 rounded-lg">router</span>
                        </div>
                        <div className="elite-card p-4 rounded-xl flex items-center justify-between">
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Active Tenants</p>
                                <p className="text-xl font-black text-slate-900">{new Set(terminalPerformance.map(t => t.tenant_id)).size}</p>
                            </div>
                            <span className="material-symbols-outlined text-blue-600 bg-blue-50 p-2 rounded-lg">group</span>
                        </div>
                        <div className="elite-card p-4 rounded-xl flex items-center justify-between">
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Ingestion Success</p>
                                <p className="text-xl font-black text-green-600">{(health?.ingestion_rate || 0).toFixed(2)}%</p>
                            </div>
                            <span className="material-symbols-outlined text-green-600 bg-green-50 p-2 rounded-lg">cloud_done</span>
                        </div>
                        <div className="elite-card p-4 rounded-xl flex items-center justify-between">
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Queue Backlog</p>
                                <p className="text-xl font-black text-slate-900">{queueBacklog}</p>
                            </div>
                            <span className="material-symbols-outlined text-blue-600 bg-blue-50 p-2 rounded-lg">reorder</span>
                        </div>
                    </div>

                    {/* Chart */}
                    <div className="lg:col-span-3 elite-card rounded-2xl p-6 h-[480px] flex flex-col">
                        <div className="flex items-center justify-between mb-8">
                            <h3 className="font-bold text-slate-900">Transaction Analytics</h3>
                            <div className="flex gap-4">
                                <div className="flex items-center gap-2">
                                    <div className="w-3 h-3 rounded-full bg-[#e11d2d]"></div>
                                    <span className="text-xs font-bold text-slate-600">Sales</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="w-3 h-3 rounded-full bg-slate-300"></div>
                                    <span className="text-xs font-bold text-slate-600">Volume</span>
                                </div>
                            </div>
                        </div>
                        <div className="flex-1 relative w-full h-full min-h-0">
                            <TransactionChart data={chartData} loading={loadingSections.charts} inline={true} />
                        </div>
                    </div>

                    {/* Heatmap */}
                    <div className="elite-card rounded-2xl p-6 flex flex-col h-[480px]">
                        <h3 className="font-bold text-slate-900 mb-1">Activity Heatmap</h3>
                        <p className="text-xs text-slate-400 mb-6">Hourly activity distribution</p>
                        <div className="flex-1 bg-slate-50 border border-dashed border-slate-200 rounded-xl flex flex-col items-center justify-center p-8 text-center">
                            <span className="material-symbols-outlined text-slate-300 text-[40px] mb-4">sensors</span>
                            <p className="text-sm font-medium text-slate-500">Live heatmap active.<br/><span className="text-xs opacity-60">Heatmap scales during peak loads.</span></p>
                        </div>
                        <div className="mt-6 grid grid-cols-6 gap-2">
                            <div className="h-4 rounded bg-[#e11d2d]/20"></div>
                            <div className="h-4 rounded bg-[#e11d2d]/10"></div>
                            <div className="h-4 rounded bg-slate-100"></div>
                            <div className="h-4 rounded bg-slate-100"></div>
                            <div className="h-4 rounded bg-slate-100"></div>
                            <div className="h-4 rounded bg-slate-100"></div>
                        </div>
                    </div>
                </section>

                {/* Bottom Tables Section */}
                <section className="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    {/* Top Terminals */}
                    <div className="elite-card rounded-2xl flex flex-col h-[500px]">
                        <div className="p-6 border-b border-slate-100">
                            <h3 className="text-xs font-bold text-blue-600 uppercase tracking-widest">Top Performing Terminals</h3>
                        </div>
                        <div className="p-4 space-y-2 overflow-y-auto flex-1">
                            {loadingSections.terminalPerformance ? (
                                <LoadingPanel label="Loading terminal details..." />
                            ) : terminalPerformance.slice(0, 10).map((tp, idx) => (
                                <div key={tp.terminal_id} className="flex items-center justify-between p-3 hover:bg-slate-50 rounded-xl transition-colors cursor-pointer group">
                                    <div className="flex items-center gap-4">
                                        <div className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center text-xs font-black text-slate-600 group-hover:bg-[#e11d2d] group-hover:text-white transition-colors">#{idx + 1}</div>
                                        <div>
                                            <p className="text-xs font-bold text-slate-900 uppercase">{tp.serial_number || tp.terminal_id || 'Unknown'}</p>
                                            <p className="text-[10px] text-slate-400 font-medium">{tp.trade_name || 'Unknown Tenant'}</p>
                                        </div>
                                    </div>
                                    <span className="text-sm font-bold text-slate-900">{currencyFormat(tp.total_sales || tp.revenue || 0)}</span>
                                </div>
                            ))}
                            {!loadingSections.terminalPerformance && terminalPerformance.length === 0 && (
                                <div className="p-4 text-center text-slate-400 text-sm">No terminals data</div>
                            )}
                        </div>
                    </div>

                    {/* Recent Activity */}
                    <div className="xl:col-span-2 elite-card rounded-2xl flex flex-col overflow-hidden h-[500px]">
                        <div className="px-6 pt-6 border-b border-slate-100 flex items-center justify-between">
                            <div className="flex gap-8">
                                <button className="pb-4 border-b-2 border-[#e11d2d] text-slate-900 text-xs font-bold uppercase tracking-wider">Transactions</button>
                                <button className="pb-4 text-slate-400 text-xs font-bold uppercase tracking-wider hover:text-slate-600 transition-colors">Exceptions ({kpiData.exceptions.value})</button>
                            </div>
                            <button className="pb-4 text-[10px] font-bold text-[#e11d2d] hover:underline uppercase tracking-widest">View Archive</button>
                        </div>
                        <div className="flex-1 flex flex-col min-h-0 relative">
                            {loadingSections.transactions || recentTransactions.length > 0 ? (
                                <RecentTransactionsTable transactions={recentTransactions} loading={loadingSections.transactions} onViewDetails={handleViewDetails} />
                            ) : (
                                <div className="flex-1 flex flex-col items-center justify-center p-20 text-center text-slate-300">
                                    <span className="material-symbols-outlined text-[48px] mb-4">history_toggle_off</span>
                                    <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">No recent transactions found</p>
                                </div>
                            )}
                        </div>
                    </div>
                </section>

                {/* System Status Footer */}
                <footer className="grid grid-cols-1 md:grid-cols-4 gap-6 pt-8 border-t border-slate-200">
                    <div className="flex items-center gap-3 px-4 py-3 bg-white rounded-xl border border-slate-200">
                        <span className="material-symbols-outlined text-orange-500">memory</span>
                        <div className="flex-1">
                            <div className="flex justify-between items-center mb-1">
                                <span className="text-[10px] font-bold text-slate-400 uppercase">CPU</span>
                                <span className="text-[10px] font-bold text-orange-500">{(health?.cpu || 0).toFixed(0)}%</span>
                            </div>
                            <div className="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full bg-orange-500" style={{ width: `${health?.cpu || 0}%` }}></div>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 px-4 py-3 bg-white rounded-xl border border-slate-200">
                        <span className="material-symbols-outlined text-green-500">analytics</span>
                        <div className="flex-1">
                            <div className="flex justify-between items-center mb-1">
                                <span className="text-[10px] font-bold text-slate-400 uppercase">Memory</span>
                                <span className="text-[10px] font-bold text-green-500">{(health?.memory || 0).toFixed(0)}%</span>
                            </div>
                            <div className="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full bg-green-500" style={{ width: `${health?.memory || 0}%` }}></div>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 px-4 py-3 bg-white rounded-xl border border-slate-200">
                        <span className="material-symbols-outlined text-green-500">reorder</span>
                        <div className="flex-1">
                            <div className="flex justify-between items-center mb-1">
                                <span className="text-[10px] font-bold text-slate-400 uppercase">Queue</span>
                                <span className="text-[10px] font-bold text-green-500">{queueBacklog < 50 ? 'Healthy' : 'Busy'}</span>
                            </div>
                            <div className="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full bg-green-500" style={{ width: `${Math.min(queueBacklog, 100)}%` }}></div>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 px-4 py-3 bg-white rounded-xl border border-slate-200">
                        <span className="material-symbols-outlined text-blue-500">lan</span>
                        <div className="flex-1">
                            <div className="flex justify-between items-center mb-1">
                                <span className="text-[10px] font-bold text-slate-400 uppercase">Network</span>
                                <span className="text-[10px] font-bold text-blue-500">Stable</span>
                            </div>
                            <div className="h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full bg-blue-500" style={{ width: '100%' }}></div>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>

        </Box>
    );
};

export default DashboardPage;
