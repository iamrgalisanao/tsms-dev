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
    Alert
} from '@mui/material';
import {
    Line
} from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
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
import GhostIcon from '@mui/icons-material/BugReport';

import '../../../css/IntakeHealth.css';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const IntakeHealthPage = () => {
    const [stats, setStats] = useState(null);
    const [history, setHistory] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [recentLogs, setRecentLogs] = useState([]);
    const [selectedLogId, setSelectedLogId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);

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
        } catch (error) {
            console.error('Error fetching intake health data:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchData(true);
        const interval = setInterval(fetchData, 5000); // Pulse heartbeat: 5s
        return () => clearInterval(interval);
    }, [fetchData]);

    const failRateValue = useMemo(() => {
        if (!stats?.metrics) return 0;
        const failed = stats.metrics['intake.failed_count'] || 0;
        const processed = stats.metrics['intake.processed_count'] || 1;
        return Math.min(100, (failed / processed) * 100);
    }, [stats]);

    const systemStatus = useMemo(() => {
        if (failRateValue > 5 || (stats?.latencies?.processing_lag_avg_s > 60)) return 'CRITICAL';
        if (failRateValue > 1 || (stats?.latencies?.processing_lag_avg_s > 30)) return 'DEGRADED';
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
                grid: { color: 'rgba(0,0,0,0.03)' },
                ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 10, weight: 800 } }
            }
        }
    }), []);

    const historyData = useMemo(() => {
        const ctx = document.createElement('canvas').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(0, 242, 255, 0.2)');
        gradient.addColorStop(1, 'rgba(0, 242, 255, 0)');

        return {
            labels: history.map(h => h.time.split(' ')[1]),
            datasets: [{
                label: 'Latency',
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
            <Box className="page-wrapper">
                <Container maxWidth="xl" sx={{ py: 4 }}>
                    {/* Mission Header */}
                    <Box sx={{ mb: 6 }}>
                        <Breadcrumbs separator={<NavigateNextIcon fontSize="small" />} sx={{ mb: 2 }}>
                            <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.5, fontSize: '0.7rem', fontWeight: 900 }}>
                                <HomeIcon sx={{ mr: 0.5, fontSize: 14 }} /> OPS_LEVEL_1
                            </MuiLink>
                            <Typography color="primary" sx={{ fontWeight: 900, fontSize: '0.7rem', letterSpacing: '0.05em' }}>SYSTEM_CORE</Typography>
                        </Breadcrumbs>

                        <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems="center" spacing={4}>
                            <Stack direction="row" spacing={3} alignItems="center">
                                <Box className="glass-container" sx={{ p: 2, bgcolor: '#101221', color: '#00f2ff', borderRadius: '20px', display: 'flex', boxShadow: '0 0 20px rgba(0,242,255,0.2)' }}>
                                    <TerminalIcon sx={{ fontSize: 40 }} />
                                </Box>
                                <Box>
                                    <Typography variant="h2" sx={{ fontWeight: 1000, letterSpacing: '-0.05em', color: '#101221', mb: 0.5 }}>
                                        Command Center
                                    </Typography>
                                    <Stack direction="row" spacing={1.5} alignItems="center">
                                        <div className="status-pulse" />
                                        <Typography variant="body2" sx={{ fontWeight: 900, opacity: 0.6, fontSize: '0.8rem' }}>
                                            PIPELINE STATUS: <span style={{ color: systemStatus === 'OPERATIONAL' ? '#00e676' : systemStatus === 'DEGRADED' ? '#feb700' : '#ff1744' }}>{systemStatus}</span>
                                        </Typography>
                                    </Stack>
                                </Box>
                            </Stack>

                            <Stack direction="row" spacing={2}>
                                <Alert 
                                    severity={systemStatus === 'OPERATIONAL' ? 'success' : systemStatus === 'DEGRADED' ? 'warning' : 'error'}
                                    icon={false}
                                    sx={{ 
                                        borderRadius: '16px', 
                                        bgcolor: 'rgba(255,255,255,0.8)', 
                                        border: '1px solid rgba(0,0,0,0.05)',
                                        fontWeight: 800,
                                        px: 3,
                                        display: { xs: 'none', lg: 'flex' }
                                    }}
                                >
                                    {systemStatus === 'OPERATIONAL' ? 'All Ingestion systems healthy' : systemStatus === 'DEGRADED' ? 'Latency exceeds standard thresholds' : 'CRITICAL: High failure rate detected'}
                                </Alert>
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

                    {/* Tenant Insight Strip */}
                    <TenantVolumeStrip tenants={tenants} />

                    {/* Core Metric Grid */}
                    <Grid container spacing={4} sx={{ mb: 8 }}>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Ingestion Lag"
                                value={`${stats?.latencies?.processing_lag_avg_s?.toFixed(2)}s`}
                                icon={<TimerIcon />}
                                color={stats?.latencies?.processing_lag_avg_s > 30 ? 'danger' : 'primary'}
                                trend={2} // Mock trend
                                sparkline={[10, 12, 11, 15, 12, 14, 13, 15]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Dispatch Backlog"
                                value={stats?.queue_size || 0}
                                icon={<FlashOnIcon />}
                                color="accent"
                                trend={-15}
                                sparkline={[50, 45, 40, 38, 42, 35, 30, 25]}
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
                                color={failRateValue > 1 ? 'danger' : 'success'}
                                trend={0.1}
                                sparkline={[99.8, 99.9, 99.7, 99.8, 99.9, 100, 100, 100]}
                            />
                        </Grid>
                    </Grid>

                    {/* Diagnostic Matrix */}
                    <Grid container spacing={4}>
                        <Grid item xs={12} lg={6}>
                            <Paper className="glass-container stagger-item" sx={{ p: 4, height: 600, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 4 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 1000, letterSpacing: '0.05em', color: '#101221' }}>
                                        TEMPORAL DRIFT (LAG)
                                    </Typography>
                                    <Chip label="PULSE_5S" color="primary" size="small" sx={{ fontWeight: 900, borderRadius: '8px', fontSize: '0.65rem' }} />
                                </Box>
                                <Box sx={{ flexGrow: 1 }}>
                                    <Line options={chartOptions} data={historyData} />
                                </Box>
                            </Paper>
                        </Grid>

                        <Grid item xs={12} lg={6}>
                            <Paper className="glass-container stagger-item" sx={{ p: 4, height: 600, display: 'flex', flexDirection: 'column' }}>
                                <Typography variant="h6" sx={{ mb: 4, fontWeight: 1000, display: 'flex', alignItems: 'center', color: '#101221' }}>
                                    <TerminalIcon sx={{ mr: 2, color: '#00f2ff' }} />
                                    DIAGNOSTIC FORENSIC FEED
                                </Typography>
                                
                                <Box sx={{ flexGrow: 1, overflowY: 'auto', pr: 1, '&::-webkit-scrollbar': { width: '4px' }, '&::-webkit-scrollbar-thumb': { bgcolor: 'rgba(0,0,0,0.1)', borderRadius: '10px' } }}>
                                    <Stack spacing={2}>
                                        {recentLogs.map((log) => (
                                            <Box key={log.id} sx={{ transition: 'all 0.3s' }}>
                                                <Box 
                                                    onClick={() => setSelectedLogId(selectedLogId === log.id ? null : log.id)}
                                                    sx={{ 
                                                        p: 2.5, 
                                                        borderRadius: '20px', 
                                                        bgcolor: 'rgba(255,255,255,0.6)',
                                                        border: '1px solid rgba(0,0,0,0.03)',
                                                        cursor: 'pointer',
                                                        borderLeft: `5px solid ${log.processing_status === 'processed' ? '#00e676' : log.processing_status === 'duplicate' ? '#00f2ff' : '#ff005c'}`,
                                                        '&:hover': { transform: 'translateX(8px)', bgcolor: 'white', borderColor: 'rgba(0,0,0,0.1)' }
                                                    }}
                                                >
                                                    <Stack direction="row" justifyContent="space-between" alignItems="center">
                                                        <Stack direction="row" spacing={2} alignItems="center">
                                                            <Avatar sx={{ bgcolor: log.processing_status === 'processed' ? 'rgba(0,230,118,0.1)' : 'rgba(0,242,255,0.1)', color: log.processing_status === 'processed' ? '#00c853' : '#00f2ff', width: 32, height: 32 }}>
                                                                {log.processing_status === 'duplicate' ? <GhostIcon sx={{ fontSize: 18 }} /> : <CheckCircleIcon sx={{ fontSize: 18 }} />}
                                                            </Avatar>
                                                            <Box>
                                                                <Typography sx={{ fontSize: '0.85rem', fontWeight: 900, color: '#101221' }}>
                                                                    {log.processing_status === 'duplicate' ? 'Ghost Ingestion Resolved' : 'Transaction Processed'}
                                                                </Typography>
                                                                <Typography sx={{ fontSize: '0.65rem', fontWeight: 800, color: 'text.secondary', opacity: 0.6 }}>
                                                                    ID: TXN_{log.id} • RECP: {log.receipt_no}
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
                                        ))}
                                        {recentLogs.length === 0 && (
                                            <Typography sx={{ py: 8, textAlign: 'center', opacity: 0.4, fontStyle: 'italic', fontWeight: 600 }}>
                                                Observing pipeline for signals...
                                            </Typography>
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
