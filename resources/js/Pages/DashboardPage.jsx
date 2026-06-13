import React, { useState, useEffect, useCallback } from 'react';
import api from '../services/api';
import MetricCard from '../Components/dashboard/MetricCard';
import TransactionChart from '../Components/dashboard/TransactionChart';
import RecentTransactionsTable from '../Components/dashboard/RecentTransactionsTable';
import SystemHealthMonitor from '../Components/dashboard/SystemHealthMonitor';
import RevenueByTerminalChart from '../Components/dashboard/RevenueByTerminalChart';
import NotificationToast from '../Components/dashboard/NotificationToast';
import TransactionDetailPanel from '../Components/transactions/TransactionDetailPanel';
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
    TextField
} from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';
import BarChartIcon from '@mui/icons-material/BarChart';
import SensorsIcon from '@mui/icons-material/Sensors';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import ListAltIcon from '@mui/icons-material/ListAlt';
import AccountBalanceWalletIcon from '@mui/icons-material/AccountBalanceWallet';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import CancelIcon from '@mui/icons-material/Cancel';
import DesktopWindowsIcon from '@mui/icons-material/DesktopWindows';
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
    const [metrics, setMetrics] = useState(null);
    const [chartData, setChartData] = useState(null);
    const [health, setHealth] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedTransaction, setSelectedTransaction] = useState(null);
    const [detailPanelOpen, setDetailPanelOpen] = useState(false);
    const [refreshInterval, setRefreshInterval] = useState(300000); // 5 minutes
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

            // Notification Detection Logic for Phase 5
            if (healthRes && healthRes.cpu > 85) {
                setNotification({ message: 'Critical high CPU usage detected! System performance may be affected.', type: 'error' });
            } else if (isInitial) {
                // Welcome/Status notification on startup
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

    const handleFilterChange = useCallback((newFilters) => {
        setFilters(newFilters);
    }, []);

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

    const handleExport = useCallback(() => {
        const queryParams = new URLSearchParams(filters).toString();
        window.open(`/api/dashboard/export-transactions?${queryParams}`, '_blank');
    }, [filters]);

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

    const activeTerminals = Number(metrics?.active_terminals?.current ?? 0);
    const totalTerminals = Number(metrics?.active_terminals?.total ?? 0);
    const offlineTerminals = Math.max(totalTerminals - activeTerminals, 0);

    const reconciled = Number(metrics?.reconciliation?.reconciled ?? metrics?.reconciled_transactions?.current ?? 0);
    const reconciliationTotal = Number(metrics?.reconciliation?.total ?? metrics?.total_transactions?.current ?? 0);
    const pendingReconciliation = Number(metrics?.reconciliation?.pending ?? Math.max(reconciliationTotal - reconciled, 0));
    const failedReconciliation = Number(metrics?.reconciliation?.failed ?? 0);
    const reconciliationRate = reconciliationTotal > 0 ? ((reconciled / reconciliationTotal) * 100).toFixed(1) : '0.0';

    const pendingUploads = Number(metrics?.pending_uploads?.current ?? health?.queues?.backlog ?? 0);
    const queueBacklog = Number(health?.queues?.backlog ?? 0);

    const chartLabels = chartData?.labels || [];
    const chartSales = chartData?.sales || [];
    const chartVolume = chartData?.volume || [];
    const peakIndex = chartSales.length > 0
        ? chartSales.reduce((bestIdx, val, idx, arr) => (val > arr[bestIdx] ? idx : bestIdx), 0)
        : -1;
    const peakLabel = peakIndex >= 0 ? chartLabels[peakIndex] : '-';
    const peakRevenue = peakIndex >= 0 ? Number(chartSales[peakIndex] || 0) : 0;
    const peakTransactions = peakIndex >= 0 ? Number(chartVolume[peakIndex] || 0) : 0;

    const topTerminal = (terminalPerformance || [])
        .map((item) => ({
            ...item,
            total_sales: Number(item.total_sales || 0)
        }))
        .sort((a, b) => b.total_sales - a.total_sales)[0];

    const exceptionRows = (auditLogs || []).filter((entry) => {
        const combined = `${entry?.level || ''} ${entry?.action || ''} ${entry?.message || ''}`.toLowerCase();
        return combined.includes('error') || combined.includes('fail') || combined.includes('exception') || combined.includes('warning');
    });

    const reconciliationRows = (auditLogs || []).filter((entry) => {
        const combined = `${entry?.action || ''} ${entry?.message || ''} ${entry?.event || ''}`.toLowerCase();
        return combined.includes('reconcil');
    });

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
                                    System Dashboard
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Live telemetry and financial orchestration center.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction="row" alignItems="center" spacing={1.5} flexWrap="wrap" useFlexGap>
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
                            onClick={() => setRefreshInterval((prev) => (prev > 0 ? 0 : 300000))}
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
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                        Last Updated: {lastUpdated ? lastUpdated.toLocaleTimeString() : 'Not yet synced'}
                    </Typography>
                </Stack>
            </Box>

            {/* Section 1: Key Performance Indicators */}
            <Box sx={{ mb: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <BarChartIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    Key Performance Indicators
                </Typography>
                <Typography variant="body2" sx={{ color: 'text.secondary', mb: 3, fontWeight: 500 }}>
                    Today vs yesterday, based on transaction timestamps.
                </Typography>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6">
                    <MetricCard
                        title="Total Revenue"
                        value={metrics?.total_sales ? currencyFormat(metrics.total_sales.current) : '₱0.00'}
                        trend={metrics?.total_sales?.trend}
                        sparkline={metrics?.total_sales?.sparkline}
                        subtitle="vs yesterday"
                        onClick={() => openTransactions()}
                        icon={<AccountBalanceWalletIcon />}
                        color="primary"
                    />
                    <MetricCard
                        title="Total Transactions"
                        value={metrics?.total_transactions?.current ?? 0}
                        trend={metrics?.total_transactions?.trend}
                        sparkline={metrics?.total_transactions?.sparkline}
                        subtitle="vs yesterday"
                        onClick={() => openTransactions()}
                        icon={<ReceiptLongIcon />}
                        color="accent"
                    />
                    <MetricCard
                        title="Reconciled"
                        value={`${reconciled} / ${reconciliationTotal}`}
                        trend={metrics?.reconciliation?.trend}
                        subtitle={`${reconciliationRate}% reconciled`}
                        onClick={() => openTransactions({ status: 'PENDING' })}
                        icon={<ListAltIcon />}
                        color="primary"
                    />
                    <MetricCard
                        title="Voided Transactions"
                        value={(() => {
                            const voidCount = metrics?.voided_transactions?.current ?? 0;
                            const voidRate = Number(metrics?.void_rate?.current ?? 0).toFixed(1);
                            return `${voidCount} (${voidRate}% )`;
                        })()}
                        trend={metrics?.voided_transactions?.trend}
                        subtitle="vs yesterday"
                        onClick={() => openTransactions({ status: 'VOIDED' })}
                        icon={<CancelIcon />}
                        color="accent"
                    />
                    <MetricCard
                        title="Active Terminals"
                        value={`${metrics?.active_terminals?.current ?? 0} / ${metrics?.active_terminals?.total ?? 0}`}
                        subtitle={`${offlineTerminals} offline`}
                        onClick={() => (window.location.href = '/terminal-tokens')}
                        icon={<DesktopWindowsIcon />}
                        color="primary"
                    />
                </div>
            </Box>

            {(dashboardView === 'operations' || dashboardView === 'audit') && (
                <Box sx={{ mb: 8 }}>
                    <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 3, textTransform: 'uppercase' }}>
                        <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                        Operational Alerts
                    </Typography>
                    <Card sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider' }}>
                        <CardContent>
                            <Grid container spacing={2} sx={{ mb: 2 }}>
                                <Grid item xs={12} md={3}>
                                    <Chip color={failedReconciliation > 0 ? 'error' : 'success'} label={`${failedReconciliation} Failed Reconciliations`} sx={{ fontWeight: 800, width: '100%' }} />
                                </Grid>
                                <Grid item xs={12} md={3}>
                                    <Chip color={pendingUploads > 0 ? 'warning' : 'success'} label={`${pendingUploads} Pending Uploads`} sx={{ fontWeight: 800, width: '100%' }} />
                                </Grid>
                                <Grid item xs={12} md={3}>
                                    <Chip color={offlineTerminals > 0 ? 'warning' : 'success'} label={`${offlineTerminals} Offline Terminals`} sx={{ fontWeight: 800, width: '100%' }} />
                                </Grid>
                                <Grid item xs={12} md={3}>
                                    <Chip color={queueBacklog > 20 ? 'warning' : 'success'} label={queueBacklog > 20 ? 'Queue Busy' : 'Queue Healthy'} sx={{ fontWeight: 800, width: '100%' }} />
                                </Grid>
                            </Grid>

                            {alerts.length > 0 ? (
                                <Stack spacing={1}>
                                    {alerts.slice(0, 4).map((alert) => {
                                        const payload = alert.data || {};
                                        const severityRaw = payload.severity || 'info';
                                        const severity =
                                            severityRaw === 'high' || severityRaw === 'error'
                                                ? 'error'
                                                : severityRaw === 'medium' || severityRaw === 'warning'
                                                    ? 'warning'
                                                    : 'info';
                                        const title = payload.title || 'System Alert';
                                        const message = payload.message || title;

                                        return (
                                            <Alert
                                                key={alert.id}
                                                severity={severity}
                                                onClose={() => handleDismissAlert(alert.id)}
                                            >
                                                <strong>{title}: </strong>
                                                {message}
                                            </Alert>
                                        );
                                    })}
                                </Stack>
                            ) : (
                                <Alert severity="success">No active critical alerts.</Alert>
                            )}
                        </CardContent>
                    </Card>
                </Box>
            )}

            {/* Section 3: Analytics Visualization */}
            {(dashboardView === 'executive' || dashboardView === 'operations') && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-10">
                <div className="lg:col-span-2 space-y-10">
                    <div>
                        <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                            <TrendingUpIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                            Sales Performance Analysis
                        </Typography>
                        <Grid container spacing={2} sx={{ mb: 2 }}>
                            <Grid item xs={12} md={6}>
                                <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider' }}>
                                    <CardContent>
                                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase' }}>
                                            Peak Hour
                                        </Typography>
                                        <Typography variant="h6" sx={{ fontWeight: 900 }}>{peakLabel || '-'}</Typography>
                                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                                            Revenue {currencyFormat(peakRevenue)} | Transactions {peakTransactions}
                                        </Typography>
                                    </CardContent>
                                </Card>
                            </Grid>
                            <Grid item xs={12} md={6}>
                                <Card sx={{ borderRadius: 2, border: '1px solid', borderColor: 'divider' }}>
                                    <CardContent>
                                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase' }}>
                                            Best Performing Tenant
                                        </Typography>
                                        <Typography variant="h6" sx={{ fontWeight: 900 }}>
                                            {topTerminal?.trade_name || 'N/A'}
                                        </Typography>
                                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                                            {currencyFormat(topTerminal?.total_sales || 0)}
                                        </Typography>
                                    </CardContent>
                                </Card>
                            </Grid>
                        </Grid>
                        <TransactionChart data={chartData} loading={loading} />
                    </div>
                </div>

                <div className="space-y-10">
                    <div>
                        <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                            <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                            Terminal Performance
                        </Typography>
                        <RevenueByTerminalChart data={terminalPerformance} loading={loading} />
                    </div>
                </div>
            </div>
            )}

            {/* Section 4: Detailed Activity */}
            <Box sx={{ mt: 10 }}>
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
                                        <TableCell sx={{ fontWeight: 800 }}>Time</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Action</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Details</TableCell>
                                        <TableCell sx={{ fontWeight: 800 }}>Actor</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {(activityTab === 'exceptions' ? exceptionRows : reconciliationRows).slice(0, 15).map((entry) => (
                                        <TableRow key={`activity-${entry.id || Math.random()}`}>
                                            <TableCell>{entry.created_at || entry.timestamp || '-'}</TableCell>
                                            <TableCell>{entry.action || entry.event || '-'}</TableCell>
                                            <TableCell>{entry.message || entry.description || '-'}</TableCell>
                                            <TableCell>{entry.user?.name || entry.user_name || entry.actor || '-'}</TableCell>
                                        </TableRow>
                                    ))}
                                    {(activityTab === 'exceptions' ? exceptionRows : reconciliationRows).length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} align="center" sx={{ color: 'text.secondary' }}>
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
