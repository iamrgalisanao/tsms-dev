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
    Alert
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

const DashboardPage = () => {
    const [metrics, setMetrics] = useState(null);
    const [chartData, setChartData] = useState(null);
    const [health, setHealth] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [recentTransactions, setRecentTransactions] = useState([]);
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedTransactionId, setSelectedTransactionId] = useState(null);
    const [detailPanelOpen, setDetailPanelOpen] = useState(false);
    const [refreshInterval, setRefreshInterval] = useState(30000); // 30 seconds
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [notification, setNotification] = useState(null);
    const [alerts, setAlerts] = useState([]);
    const [filters, setFilters] = useState({
        start_date: '',
        end_date: '',
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

    const handleFilterChange = useCallback((newFilters) => {
        setFilters(newFilters);
    }, []);

    const handleExport = useCallback(() => {
        const queryParams = new URLSearchParams(filters).toString();
        window.open(`/api/dashboard/export-transactions?${queryParams}`, '_blank');
    }, [filters]);

    const handleViewDetails = useCallback((transaction) => {
        setSelectedTransactionId(transaction.id || transaction.transaction_id);
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

                {alerts.length > 0 && (
                    <Stack spacing={1} sx={{ mb: 3 }}>
                        {alerts.map((alert) => {
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
                )}

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

                    <Stack direction="row" alignItems="center" spacing={2}>
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
                        <Divider orientation="vertical" flexItem sx={{ mx: 1, height: 40 }} />
                        <FormControl variant="outlined" size="small">
                            <Select
                                id="time-range-select"
                                defaultValue="today"
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
                                <MenuItem value="today">Today (Real-time)</MenuItem>
                                <MenuItem value="yesterday">Yesterday</MenuItem>
                                <MenuItem value="7days">Last 7 Days</MenuItem>
                                <MenuItem value="30days">Last 30 Days</MenuItem>
                                <MenuItem value="custom">Custom Range...</MenuItem>
                            </Select>
                        </FormControl>
                    </Stack>
                </Stack>
            </Box>

            {/* Section 1: System Status */}
            <Box sx={{ mb: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <SensorsIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    System Status & Health
                </Typography>
                <SystemHealthMonitor health={health} loading={loading} />
            </Box>

            {/* Section 2: Key Performance Indicators */}
            <Box sx={{ mb: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <BarChartIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    Key Performance Indicators
                </Typography>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                    <MetricCard
                        title="Total Revenue"
                        value={metrics?.total_revenue ? currencyFormat(metrics.total_revenue.current) : '₱0.00'}
                        trend={metrics?.total_revenue?.trend}
                        sparkline={metrics?.total_revenue?.sparkline}
                        icon={<AccountBalanceWalletIcon />}
                        color="primary"
                    />
                    <MetricCard
                        title="Total Transactions"
                        value={metrics?.total_transactions?.current ?? 0}
                        trend={metrics?.total_transactions?.trend}
                        sparkline={metrics?.total_transactions?.sparkline}
                        icon={<ReceiptLongIcon />}
                        color="accent"
                    />
                    <MetricCard
                        title="Voided Transactions"
                        value={metrics?.voided_transactions?.current ?? 0}
                        trend={metrics?.voided_transactions?.trend}
                        icon={<CancelIcon />}
                        color="accent"
                    />
                    <MetricCard
                        title="Active Terminals"
                        value={`${metrics?.active_terminals?.current ?? 0} / ${metrics?.active_terminals?.total ?? 0}`}
                        icon={<DesktopWindowsIcon />}
                        color="primary"
                    />
                </div>
            </Box>

            {/* Section 3: Analytics Visualization */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-10">
                <div className="lg:col-span-2 space-y-10">
                    <div>
                        <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                            <TrendingUpIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                            Sales Performance Analysis
                        </Typography>
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

            {/* Section 4: Detailed Activity */}
            <Box sx={{ mt: 10 }}>
                <Typography variant="h2" color="primary" sx={{ display: 'flex', alignItems: 'center', mb: 4, textTransform: 'uppercase' }}>
                    <ListAltIcon sx={{ mr: 2, bgcolor: 'primary.main', color: 'white', p: 1, borderRadius: 2, fontSize: 40 }} />
                    Recent Activity Logs
                </Typography>
                <Box sx={{ bgcolor: 'white', borderRadius: '2rem', shadow: '0 20px 40px rgba(0,0,0,0.05)', border: '1px solid', borderColor: 'grey.100', overflow: 'hidden' }}>
                    <RecentTransactionsTable
                        transactions={recentTransactions}
                        loading={loading}
                        onForward={handleViewDetails}
                    />
                </Box>
            </Box>

            <TransactionDetailPanel
                transactionId={selectedTransactionId}
                open={detailPanelOpen}
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
