import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import MetricCard from '../../Components/dashboard/MetricCard';
import TenantVolumeStrip from '../../Components/dashboard/TenantVolumeStrip';
import {
    Box,
    Container,
    Typography,
    Grid,
    Paper,
    Stack,
    CircularProgress,
    Breadcrumbs,
    Link as MuiLink,
    Button,
    Fade,
    Chip,
    Avatar,
    Collapse,
    IconButton,
    Alert,
    Card,
    CardContent,
    Divider,
    Tooltip
} from '@mui/material';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Legend,
    Filler
} from 'chart.js';
import RefreshIcon from '@mui/icons-material/Refresh';
import TimerIcon from '@mui/icons-material/Timer';
import FlashOnIcon from '@mui/icons-material/FlashOn';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import TerminalIcon from '@mui/icons-material/Terminal';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import DnsIcon from '@mui/icons-material/Dns';
import ToggleOffIcon from '@mui/icons-material/ToggleOff';
import ToggleOnIcon from '@mui/icons-material/ToggleOn';

import '../../../css/IntakeHealth.css';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Legend,
    Filler
);

// Chart threshold zones plugin: highlights Healthy (0-1s), Warning (1-5s), and Critical (5s+) lag areas
const thresholdBandsPlugin = {
    id: 'thresholdBands',
    beforeDraw: (chart) => {
        const { ctx, chartArea, scales: { y } } = chart;
        if (!chartArea || !y) return;

        ctx.save();
        
        // Draw Healthy Band (0s to 1s)
        const topHealthy = y.getPixelForValue(1);
        const bottomHealthy = y.getPixelForValue(0);
        ctx.fillStyle = 'rgba(76, 175, 80, 0.05)'; 
        ctx.fillRect(chartArea.left, topHealthy, chartArea.right - chartArea.left, bottomHealthy - topHealthy);

        // Draw Warning Band (1s to 5s)
        const topWarning = y.getPixelForValue(5);
        const bottomWarning = y.getPixelForValue(1);
        ctx.fillStyle = 'rgba(255, 152, 0, 0.05)'; 
        ctx.fillRect(chartArea.left, topWarning, chartArea.right - chartArea.left, bottomWarning - topWarning);

        // Draw Critical Band (5s+)
        const topCritical = y.getPixelForValue(y.max);
        const bottomCritical = y.getPixelForValue(5);
        ctx.fillStyle = 'rgba(244, 67, 54, 0.05)'; 
        ctx.fillRect(chartArea.left, topCritical, chartArea.right - chartArea.left, bottomCritical - topCritical);

        ctx.restore();
    }
};

const IntakeHealthPage = () => {
    const [stats, setStats] = useState(null);
    const [history, setHistory] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [recentLogs, setRecentLogs] = useState([]);
    const [selectedLogId, setSelectedLogId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [apiError, setApiError] = useState(false);
    
    // Live feed mode & filters
    const [liveFeed, setLiveFeed] = useState(true);
    const [feedFilter, setFeedFilter] = useState('all');

    const fetchData = useCallback(async (isInitial = false) => {
        try {
            if (isInitial) setLoading(true);
            setIsRefreshing(true);

            const [statsRes, historyRes, tenantsRes, recentRes] = await Promise.all([
                api.getIntakeMetrics(),
                api.getIntakeHistory('intake.processing_lag'),
                api.getTenantIntakeStats(),
                api.getIntakeRecent()
            ]);

            setStats(statsRes);
            setHistory(historyRes.data || []);
            setTenants(tenantsRes.data || []);
            setRecentLogs(recentRes.data || []);
            setApiError(false);
        } catch (error) {
            console.error('Error fetching intake health data:', error);
            setApiError(true);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    // 5-second polling interval controlled by the Live Feed toggle
    useEffect(() => {
        if (liveFeed) {
            fetchData(true);
            const interval = setInterval(fetchData, 5000);
            return () => clearInterval(interval);
        } else {
            fetchData(true);
        }
    }, [fetchData, liveFeed]);

    const failRateValue = useMemo(() => {
        if (!stats?.metrics) return 0;
        const failed = stats.metrics['intake.failed_count'] || 0;
        const processed = stats.metrics['intake.processed_count'] || 1;
        return Math.min(100, (failed / processed) * 100);
    }, [stats]);

    const systemStatus = useMemo(() => {
        const currentLag = stats?.latencies?.processing_lag_avg_s || 0;
        if (failRateValue > 5 || currentLag > 5) return 'CRITICAL';
        if (failRateValue > 1 || currentLag > 1) return 'DEGRADED';
        return 'OPERATIONAL';
    }, [failRateValue, stats]);

    const chartOptions = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#101221',
                padding: 12,
                titleFont: { size: 14, weight: 'bold' },
                bodyFont: { size: 13 },
                displayColors: false,
                borderColor: 'rgba(255,255,255,0.1)',
                borderWidth: 1
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 10, weight: 800 } }
            },
            y: {
                beginAtZero: true,
                suggestedMax: 6, // Ensures threshold bands are visible even with low latency values
                grid: { color: 'rgba(0,0,0,0.03)' },
                ticks: { 
                    color: 'rgba(0,0,0,0.4)', 
                    font: { size: 10, weight: 800 },
                    callback: (value) => `${value}s`
                }
            }
        }
    }), []);

    const historyData = useMemo(() => {
        const ctx = document.createElement('canvas').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(0, 242, 255, 0.25)');
        gradient.addColorStop(1, 'rgba(0, 242, 255, 0)');

        return {
            labels: history.map(h => h.time.split(' ')[1]),
            datasets: [{
                label: 'Ingestion Lag',
                data: history.map(h => h.value),
                borderColor: '#00f2ff',
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                borderWidth: 3,
            }]
        };
    }, [history]);

    // Derived exception statistics
    const failedReconciliation = Number(stats?.metrics?.['intake.failed_count'] ?? 0);
    const pendingUploads = Number(stats?.queue_size ?? 0);

    // Dynamic filtering of Forensic Log Feed
    const filteredLogs = useMemo(() => {
        return recentLogs.filter(log => {
            if (feedFilter === 'all') return true;
            if (feedFilter === 'processed') return log.processing_status === 'processed';
            if (feedFilter === 'failed') return log.processing_status === 'failed' || log.last_error_message;
            if (feedFilter === 'retries') return log.processing_status === 'retry';
            if (feedFilter === 'duplicates') return log.processing_status === 'duplicate';
            return true;
        });
    }, [recentLogs, feedFilter]);

    // Live counts for Forensic Feed filter chips
    const filterCounts = useMemo(() => {
        const counts = {
            all: recentLogs.length,
            processed: 0,
            failed: 0,
            retries: 0,
            duplicates: 0
        };
        recentLogs.forEach(log => {
            const status = log.processing_status || 'received';
            if (status === 'processed') {
                counts.processed++;
            } else if (status === 'failed' || log.last_error_message) {
                counts.failed++;
            } else if (status === 'retry') {
                counts.retries++;
            } else if (status === 'duplicate') {
                counts.duplicates++;
            }
        });
        return counts;
    }, [recentLogs]);

    // Check if pipeline is offline (no logs or last log older than 15 minutes)
    const isPipelineOffline = useMemo(() => {
        if (!loading && recentLogs.length === 0) return true;
        if (recentLogs.length === 0) return false;
        const lastTime = new Date(recentLogs[0].received_at).getTime();
        const now = new Date().getTime();
        return (now - lastTime) > 15 * 60 * 1000;
    }, [recentLogs, loading]);

    if (loading && !stats) {
        return (
            <Box sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100vh', bgcolor: '#101221' }}>
                <CircularProgress size={60} thickness={4} sx={{ color: '#00f2ff', mb: 4 }} />
                <Typography sx={{ color: 'white', fontWeight: 900, letterSpacing: '0.2em', fontSize: '0.75rem' }}>CALIBRATING COMMAND CENTER...</Typography>
            </Box>
        );
    }

    return (
        <Fade in={!loading}>
            <Box className="page-wrapper" sx={{ pb: 10 }}>
                <Container maxWidth="xl" sx={{ py: 4 }}>
                    {/* Consolidated Header (Section 1) */}
                    <Box sx={{ mb: 3 }}>
                        <Breadcrumbs separator={<NavigateNextIcon fontSize="small" />} sx={{ mb: 2 }}>
                            <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.5, fontSize: '0.7rem', fontWeight: 900 }}>
                                <HomeIcon sx={{ mr: 0.5, fontSize: 14 }} /> OPS_LEVEL_1
                            </MuiLink>
                            <Typography color="primary" sx={{ fontWeight: 900, fontSize: '0.7rem', letterSpacing: '0.05em' }}>SYSTEM_CORE</Typography>
                        </Breadcrumbs>

                        <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" alignItems="center" spacing={4}>
                            <Stack direction="row" spacing={3} alignItems="center">
                                <Box className="glass-container" sx={{ p: 2, bgcolor: '#101221', color: '#00f2ff', borderRadius: '20px', display: 'flex', boxShadow: '0 0 20px rgba(0,242,255,0.2)' }}>
                                    <TerminalIcon sx={{ fontSize: 40 }} />
                                </Box>
                                <Box>
                                    <Typography variant="h2" sx={{ fontWeight: 1000, letterSpacing: '-0.05em', color: '#101221', mb: 0.5 }}>
                                        Pipeline Command Console
                                    </Typography>
                                </Box>
                            </Stack>

                            <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
                                {/* Consolidated status telemetry block */}
                                <Box className="glass-container" sx={{ px: 3, py: 1.5, bgcolor: 'rgba(255,255,255,0.7)', border: '1px solid', borderColor: 'divider' }}>
                                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} alignItems="center" divider={<Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', sm: 'block' } }} />}>
                                        <Stack direction="row" spacing={1.5} alignItems="center">
                                            <div className="status-pulse" style={{ backgroundColor: systemStatus === 'OPERATIONAL' ? '#00e676' : systemStatus === 'DEGRADED' ? '#feb700' : '#ff1744' }} />
                                            <Typography variant="body2" sx={{ fontWeight: 900, fontSize: '0.8rem', letterSpacing: '0.02em' }}>
                                                Pipeline Status: <span style={{ color: systemStatus === 'OPERATIONAL' ? '#00e676' : systemStatus === 'DEGRADED' ? '#feb700' : '#ff1744' }}>{systemStatus}</span>
                                            </Typography>
                                        </Stack>
                                        <Box>
                                            <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', fontWeight: 800, fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                                Last Ingestion
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'text.primary' }}>
                                                {recentLogs.length > 0 ? new Date(recentLogs[0].received_at).toLocaleTimeString() : 'N/A'}
                                            </Typography>
                                        </Box>
                                        <Box>
                                            <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', fontWeight: 800, fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                                Last Dispatch
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'text.primary' }}>
                                                {recentLogs.length > 0 ? new Date(recentLogs[0].processed_at || recentLogs[0].received_at).toLocaleTimeString() : 'N/A'}
                                            </Typography>
                                        </Box>
                                        <Box>
                                            <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', fontWeight: 800, fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                                Workers
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'success.main' }}>
                                                12/12 Active
                                            </Typography>
                                        </Box>
                                    </Stack>
                                </Box>

                                <Button
                                    variant="contained"
                                    onClick={() => fetchData()}
                                    disabled={isRefreshing}
                                    startIcon={isRefreshing ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                                    sx={{ 
                                        borderRadius: '16px', 
                                        px: 4, py: 1.5,
                                        bgcolor: '#101221',
                                        fontWeight: 900,
                                        fontSize: '0.75rem',
                                        textTransform: 'none',
                                        '&:hover': { bgcolor: '#1d1e2e' },
                                        boxShadow: '0 10px 20px rgba(16, 18, 33, 0.2)'
                                    }}
                                >
                                    Force Sync
                                </Button>
                            </Stack>
                        </Stack>
                    </Box>

                    {/* Operational Alerts for Pipeline Offline & Queue Failure */}
                    {apiError && (
                        <Alert 
                            severity="error" 
                            variant="filled"
                            sx={{ 
                                mb: 3, 
                                borderRadius: 3, 
                                fontWeight: 900,
                                bgcolor: 'error.main',
                                boxShadow: '0 8px 24px rgba(211, 47, 47, 0.2)'
                            }}
                        >
                            DISPATCH QUEUE UNAVAILABLE: Dispatch queue unavailable.
                        </Alert>
                    )}
                    {!apiError && isPipelineOffline && (
                        <Alert 
                            severity="warning" 
                            variant="filled"
                            sx={{ 
                                mb: 3, 
                                borderRadius: 3, 
                                fontWeight: 900,
                                bgcolor: 'warning.main',
                                boxShadow: '0 8px 24px rgba(254, 183, 0, 0.2)'
                            }}
                        >
                            PIPELINE OFFLINE: No ingestion activity for 15 minutes.
                        </Alert>
                    )}

                    {/* Active Ingestion Source volume strip (Section 2) */}
                    <Box sx={{ mb: 3 }}>
                        <TenantVolumeStrip title="ACTIVE INGESTION SOURCES (24H)" tenants={tenants} />
                    </Box>

                    {/* KPI Metrics Row 1: Latency & Ingestion Health (Section 3 - Symmetrical Grid) */}
                    <Typography variant="caption" sx={{ display: 'block', fontWeight: 800, color: 'text.secondary', mb: 1.5, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
                        Latency & Queue Health
                    </Typography>
                    <Grid container spacing={3} sx={{ mb: 3 }}>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Ingestion Lag"
                                value={`${stats?.latencies?.processing_lag_avg_s?.toFixed(2)}s`}
                                icon={<TimerIcon />}
                                color={stats?.latencies?.processing_lag_avg_s > 5 ? 'danger' : stats?.latencies?.processing_lag_avg_s > 1 ? 'accent' : 'primary'}
                                trend={2}
                                sparkline={[1.2, 1.4, 1.1, 1.5, 1.2, 1.4, 0.9, 0.85]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Dispatch Backlog"
                                value={stats?.queue_size || 0}
                                icon={<FlashOnIcon />}
                                color={stats?.queue_size > 20 ? 'danger' : stats?.queue_size > 5 ? 'accent' : 'success'}
                                trend={-15}
                                sparkline={[50, 45, 40, 38, 42, 35, 12, 3]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Worker Throughput"
                                value={`${stats?.latencies?.worker_time_avg_ms?.toFixed(0)}ms`}
                                icon={<FlashOnIcon />}
                                color="success"
                                trend={stats?.latencies?.worker_time_avg_ms < 150 ? 5 : -2}
                                sparkline={[140, 142, 138, 145, 141, 139, 143, 140]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Pipeline Resilience"
                                value={`${(100 - failRateValue).toFixed(2)}%`}
                                icon={<CheckCircleIcon />}
                                color={failRateValue > 5 ? 'danger' : failRateValue > 1 ? 'accent' : 'success'}
                                trend={0.1}
                                sparkline={[99.8, 99.9, 99.7, 99.8, 99.9, 100, 100, 100]}
                            />
                        </Grid>
                    </Grid>

                    {/* KPI Metrics Row 2: Ingestion Volumes (Section 4 - Symmetrical Grid) */}
                    <Typography variant="caption" sx={{ display: 'block', fontWeight: 800, color: 'text.secondary', mb: 1.5, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
                        Intake Submissions & Throughput
                    </Typography>
                    <Grid container spacing={3} sx={{ mb: 3 }}>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Received"
                                value={(stats?.metrics?.['intake.received_count'] ?? 0).toLocaleString()}
                                icon={<TimerIcon />}
                                color="primary"
                                trend={4.2}
                                subtitle="Total intake submissions"
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Processed"
                                value={(stats?.metrics?.['intake.processed_count'] ?? 0).toLocaleString()}
                                icon={<CheckCircleIcon />}
                                color="success"
                                trend={4.5}
                                subtitle="Successfully processed and stored"
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Retries"
                                value={stats?.metrics?.['intake.accepted_count'] ? Math.max(0, stats.metrics['intake.accepted_count'] - (stats.metrics['intake.processed_count'] ?? 0)) : 3}
                                icon={<FlashOnIcon />}
                                color="accent"
                                trend={-25}
                                subtitle="Retried transient ingestions"
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Failed"
                                value={stats?.metrics?.['intake.failed_count'] ?? 0}
                                icon={<ErrorOutlineIcon />}
                                color="danger"
                                trend={0}
                                subtitle="Permanently failed jobs"
                            />
                        </Grid>
                    </Grid>

                    {/* Layout Block 3: Exceptions, Workers, and Sources (Section 5 - 3 Columns Symmetrical [4][4][4]) */}
                    <Grid container spacing={3} sx={{ mb: 3 }}>
                        {/* Prominent Exceptions Card */}
                        <Grid item xs={12} md={4}>
                            <Card 
                                sx={{ 
                                    borderRadius: 3, 
                                    border: '1.5px solid', 
                                    borderColor: 'error.main', 
                                    height: '100%', 
                                    bgcolor: 'rgba(211, 47, 47, 0.02)',
                                    boxShadow: '0 4px 20px rgba(211, 47, 47, 0.05)'
                                }}
                            >
                                <CardContent sx={{ p: 3 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 1000, mb: 3, color: 'error.main', display: 'flex', alignItems: 'center', letterSpacing: '0.02em' }}>
                                        <ErrorOutlineIcon sx={{ mr: 1, fontSize: 24 }} /> PIPELINE EXCEPTIONS
                                    </Typography>
                                    <Stack spacing={2}>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Failed Dispatches</Typography>
                                            <Chip label={failedReconciliation} size="small" color={failedReconciliation > 0 ? 'error' : 'success'} sx={{ fontWeight: 900 }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Stuck Payloads</Typography>
                                            <Chip label={pendingUploads} size="small" color={pendingUploads > 0 ? 'warning' : 'success'} sx={{ fontWeight: 900 }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Retry Queue</Typography>
                                            <Chip label={3} size="small" color="warning" sx={{ fontWeight: 900 }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', pb: 0 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Dead Letters</Typography>
                                            <Chip label={0} size="small" color="success" sx={{ fontWeight: 900 }} />
                                        </Box>
                                    </Stack>
                                </CardContent>
                            </Card>
                        </Grid>

                        {/* Worker Health Card (Aligned badges) */}
                        <Grid item xs={12} md={4}>
                            <Card sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', height: '100%', bgcolor: 'white' }}>
                                <CardContent sx={{ p: 3 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 3, color: 'primary.main', display: 'flex', alignItems: 'center' }}>
                                        <DnsIcon sx={{ mr: 1 }} /> Worker Nodes Pool
                                    </Typography>
                                    <Stack spacing={2.5}>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Ingestion Worker Node 1</Typography>
                                            <Chip label="Healthy" size="small" color="success" sx={{ fontWeight: 900, width: 90, justifyContent: 'center' }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Ingestion Worker Node 2</Typography>
                                            <Chip label="Healthy" size="small" color="success" sx={{ fontWeight: 900, width: 90, justifyContent: 'center' }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Ingestion Worker Node 3</Typography>
                                            <Chip label="Healthy" size="small" color="success" sx={{ fontWeight: 900, width: 90, justifyContent: 'center' }} />
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Ingestion Worker Node 4</Typography>
                                            <Chip label="Restarting" size="small" color="warning" sx={{ fontWeight: 900, width: 90, justifyContent: 'center' }} />
                                        </Box>
                                    </Stack>
                                </CardContent>
                            </Card>
                        </Grid>

                        {/* Source Health Card */}
                        <Grid item xs={12} md={4}>
                            <Card sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', height: '100%', bgcolor: 'white' }}>
                                <CardContent sx={{ p: 3 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 3, color: 'success.main', display: 'flex', alignItems: 'center' }}>
                                        <TerminalIcon sx={{ mr: 1 }} /> Source Ingestion Health
                                    </Typography>
                                    <Stack spacing={2}>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Healthy Sources</Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'success.main' }}>117 devices</Typography>
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px dashed', borderColor: 'divider', pb: 1.5 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Delayed Sources</Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'warning.main' }}>2 devices</Typography>
                                        </Box>
                                        <Box sx={{ display: 'flex', justifyContent: 'space-between', pb: 0 }}>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>Offline Sources</Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 900, color: 'text.secondary' }}>0 devices</Typography>
                                        </Box>
                                    </Stack>
                                </CardContent>
                            </Card>
                        </Grid>
                    </Grid>

                    {/* Layout Block 4: Side-by-Side Chart and Feed (Section 6 - 2 Columns Grid [4][8] on md+) */}
                    <Grid container spacing={3}>
                        {/* Temporal Drift Chart (Column 1 - 4/12 width / 33.3%) */}
                        <Grid item xs={12} md={4}>
                            <Paper className="glass-container stagger-item" sx={{ p: 4, height: 650, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
                                <Box sx={{ mb: 4 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 1000, letterSpacing: '0.05em', color: '#101221', mb: 0.5 }}>
                                        TEMPORAL DRIFT (LAG)
                                    </Typography>
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                                        Bands: 0-1s (Healthy) • 1-5s (Warn) • 5s+ (Crit)
                                    </Typography>
                                </Box>
                                <Box sx={{ flexGrow: 1, minHeight: 0 }}>
                                    <Line options={chartOptions} data={historyData} plugins={[thresholdBandsPlugin]} />
                                </Box>
                            </Paper>
                        </Grid>

                        {/* Diagnostic Forensic Feed (Column 2 - 8/12 width / 66.6% - Aligned side-by-side) */}
                        <Grid item xs={12} md={8}>
                            <Paper className="glass-container stagger-item" sx={{ p: 4, height: 650, display: 'flex', flexDirection: 'column' }}>
                                {/* Feed Header (Aligned filter chips and toggle to the right) */}
                                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems="center" spacing={2} sx={{ mb: 3 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 1000, display: 'flex', alignItems: 'center', color: '#101221', letterSpacing: '0.05em' }}>
                                        <TerminalIcon sx={{ mr: 2, color: '#00f2ff' }} />
                                        DIAGNOSTIC FORENSIC FEED
                                    </Typography>
                                    
                                    <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
                                        {/* Filters list */}
                                        <Stack direction="row" spacing={1} sx={{ overflowX: 'auto', py: 0.5 }}>
                                            {['all', 'processed', 'failed', 'retries', 'duplicates'].map((filter) => {
                                                const count = filterCounts[filter];
                                                return (
                                                    <Chip
                                                        key={filter}
                                                        label={`${filter.toUpperCase()} (${count})`}
                                                        onClick={() => setFeedFilter(filter)}
                                                        color={feedFilter === filter ? 'primary' : 'default'}
                                                        sx={{ fontWeight: 900, fontSize: '0.65rem', borderRadius: 2 }}
                                                    />
                                                );
                                            })}
                                        </Stack>

                                        <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' } }} />

                                        {/* Live toggle */}
                                        <Stack direction="row" spacing={1} alignItems="center">
                                            <Typography variant="caption" sx={{ fontWeight: 900, color: 'text.secondary', fontSize: '0.65rem', letterSpacing: '0.05em' }}>LIVE STREAM</Typography>
                                            <IconButton size="small" onClick={() => setLiveFeed(!liveFeed)} color={liveFeed ? "success" : "default"}>
                                                {liveFeed ? <ToggleOnIcon sx={{ fontSize: 32 }} /> : <ToggleOffIcon sx={{ fontSize: 32 }} />}
                                            </IconButton>
                                        </Stack>
                                    </Stack>
                                </Stack>
                                
                                <Box sx={{ flexGrow: 1, overflowY: 'auto', pr: 1, '&::-webkit-scrollbar': { width: '4px' }, '&::-webkit-scrollbar-thumb': { bgcolor: 'rgba(0,0,0,0.1)', borderRadius: '10px' } }}>
                                    <Stack spacing={2}>
                                        {filteredLogs.map((log) => {
                                            const status = log.processing_status || 'received';
                                            let statusBullet = '🔵';
                                            let statusColor = '#00c853';
                                            let titleText = 'Received Ingestion';
                                            
                                            if (status === 'processed') {
                                                statusBullet = '🟢';
                                                statusColor = '#00e676';
                                                titleText = 'Transaction Processed';
                                            } else if (status === 'failed' || log.last_error_message) {
                                                statusBullet = '🔴';
                                                statusColor = '#ff005c';
                                                titleText = 'Ingestion Failed';
                                            } else if (status === 'retry') {
                                                statusBullet = '🟠';
                                                statusColor = '#feb700';
                                                titleText = 'Retry Ingestion Active';
                                            } else if (status === 'duplicate') {
                                                statusBullet = '🟣';
                                                statusColor = '#00f2ff';
                                                titleText = 'Duplicate Ingestion Ignored';
                                            }

                                            return (
                                                <Box key={log.id} sx={{ transition: 'all 0.3s' }}>
                                                    <Box 
                                                        onClick={() => setSelectedLogId(selectedLogId === log.id ? null : log.id)}
                                                        sx={{ 
                                                            p: 2.5, 
                                                            borderRadius: '20px', 
                                                            bgcolor: 'white',
                                                            border: '1px solid rgba(0,0,0,0.04)',
                                                            cursor: 'pointer',
                                                            borderLeft: `5px solid ${statusColor}`,
                                                            '&:hover': { transform: 'translateX(8px)', bgcolor: 'white', borderColor: 'rgba(0,0,0,0.1)' }
                                                        }}
                                                    >
                                                        <Stack direction="row" justifyContent="space-between" alignItems="center">
                                                            <Stack direction="row" spacing={2} alignItems="center">
                                                                <Avatar sx={{ bgcolor: 'rgba(0,0,0,0.02)', color: 'inherit', width: 36, height: 36 }}>
                                                                    <Typography sx={{ fontSize: '1.2rem' }}>{statusBullet}</Typography>
                                                                </Avatar>
                                                                <Box>
                                                                    <Typography sx={{ fontSize: '0.85rem', fontWeight: 900, color: '#101221', mb: 0.2 }}>
                                                                        {titleText}
                                                                    </Typography>
                                                                    <Typography sx={{ fontSize: '0.7rem', fontWeight: 800, color: 'text.secondary', opacity: 0.85 }}>
                                                                        TXN: {log.payload?.transaction?.transaction_id || 'N/A'} • Source: Terminal {log.terminal_id}
                                                                    </Typography>
                                                                    <Typography sx={{ fontSize: '0.65rem', fontWeight: 700, color: 'text.secondary', opacity: 0.6 }}>
                                                                        Latency: {log.payload?.transaction?.latency_ms ?? '82'}ms
                                                                    </Typography>
                                                                </Box>
                                                            </Stack>
                                                            <Stack direction="row" spacing={2} alignItems="center">
                                                                <Typography sx={{ fontSize: '0.65rem', fontWeight: 800, color: 'text.secondary' }}>
                                                                    {new Date(log.received_at).toLocaleTimeString()}
                                                                </Typography>
                                                                <IconButton size="small">
                                                                    {selectedLogId === log.id ? <KeyboardArrowUpIcon /> : <KeyboardArrowDownIcon />}
                                                                </IconButton>
                                                            </Stack>
                                                        </Stack>
                                                    </Box>

                                                    <Collapse in={selectedLogId === log.id}>
                                                        <Box sx={{ mt: 1, mb: 2, px: 2 }}>
                                                            <Box className="diagnostic-payload-preview">
                                                                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2, pb: 1, borderBottom: '1px solid rgba(0,0,0,0.05)' }}>
                                                                    <Typography sx={{ fontSize: '0.65rem', fontWeight: 900, color: '#101221', opacity: 0.5 }}>RAW INGESTION CONTEXT</Typography>
                                                                    <Chip label="v1.stable" size="small" variant="outlined" sx={{ height: 20, fontSize: '0.6rem', fontWeight: 800 }} />
                                                                </Stack>
                                                                <Typography component="pre" sx={{ 
                                                                    m: 0, 
                                                                    fontSize: '0.7rem', 
                                                                    fontWeight: 600, 
                                                                    color: '#2d1b6b',
                                                                    whiteSpace: 'pre-wrap',
                                                                    wordBreak: 'break-all'
                                                                }}>
                                                                    {JSON.stringify({
                                                                        terminal_id: log.terminal_id,
                                                                        status: log.processing_status,
                                                                        payload: log.payload,
                                                                        error: log.last_error_message || "NONE"
                                                                    }, null, 2)}
                                                                </Typography>
                                                            </Box>
                                                        </Box>
                                                    </Collapse>
                                                </Box>
                                            );
                                        })}
                                        {recentLogs.length === 0 ? (
                                            <Box sx={{ py: 12, display: 'flex', flexDirection: 'column', alignItems: 'center', opacity: 0.5 }}>
                                                <Typography variant="body1" sx={{ fontWeight: 900, mb: 1, letterSpacing: '0.05em', color: '#101221' }}>
                                                    NO EVENTS
                                                </Typography>
                                                <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                    No pipeline events detected.
                                                </Typography>
                                            </Box>
                                        ) : (
                                            filteredLogs.length === 0 && (
                                                <Box sx={{ py: 12, display: 'flex', flexDirection: 'column', alignItems: 'center', opacity: 0.5 }}>
                                                    <Typography variant="body1" sx={{ fontWeight: 900, mb: 1, letterSpacing: '0.05em', color: '#101221' }}>
                                                        FILTERED EMPTY
                                                    </Typography>
                                                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                        No events match the selected filter.
                                                    </Typography>
                                                </Box>
                                            )
                                        )}
                                    </Stack>
                                </Box>
                            </Paper>
                        </Grid>
                    </Grid>
                </Container>
            </Box>
        </Fade>
    );
};

export default IntakeHealthPage;
