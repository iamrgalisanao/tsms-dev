import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import MetricCard from '../../Components/dashboard/MetricCard';
import {
    Box,
    Typography,
    Grid,
    Paper,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Stack,
    CircularProgress,
    Breadcrumbs,
    Link as MuiLink,
    Button,
    Fade,
    Chip,
    Avatar
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
import GroupsIcon from '@mui/icons-material/Groups';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import TerminalIcon from '@mui/icons-material/Terminal';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import GhostIcon from '@mui/icons-material/BugReport'; // Using BugReport as Ghost icon

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
        const interval = setInterval(fetchData, 10000); // Higher frequency for Command Center
        return () => clearInterval(interval);
    }, [fetchData]);

    const chartOptions = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a103d',
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
                ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 10, weight: 700 } }
            },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.03)' },
                ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 10, weight: 700 } }
            }
        }
    }), []);

    const historyData = useMemo(() => {
        const ctx = document.createElement('canvas').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(76, 201, 240, 0.3)');
        gradient.addColorStop(1, 'rgba(76, 201, 240, 0)');

        return {
            labels: history.map(h => h.time.split(' ')[1]),
            datasets: [{
                label: 'Latency',
                data: history.map(h => h.value),
                borderColor: '#4cc9f0',
                backgroundColor: gradient,
                fill: true,
                tension: 0.5,
                pointRadius: 0,
                borderWidth: 3,
            }]
        };
    }, [history]);

    if (loading && !stats) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '80vh' }}>
                <CircularProgress size={60} thickness={4} sx={{ color: '#1a103d' }} />
            </Box>
        );
    }

    const failRateValue = Math.min(100, (stats?.metrics?.['intake.failed_count'] / (stats?.metrics?.['intake.processed_count'] || 1)) * 100);

    return (
        <Fade in={!loading}>
            <Box className="page-wrapper" sx={{ overflow: 'hidden', minHeight: '100vh', bgcolor: '#fbfbfd' }}>
                <Container maxWidth="xl" sx={{ py: 4 }}>
                    {/* Header Section */}
                    <Box sx={{ mb: 6 }}>
                        <Breadcrumbs separator={<NavigateNextIcon fontSize="small" />} sx={{ mb: 2 }}>
                            <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.5, fontSize: '0.75rem', fontWeight: 800 }}>
                                <HomeIcon sx={{ mr: 0.5, fontSize: 14 }} /> SYSTEM
                            </MuiLink>
                            <Typography color="primary" sx={{ fontWeight: 900, fontSize: '0.75rem' }}>COMMAND CENTER</Typography>
                        </Breadcrumbs>

                        <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} spacing={3}>
                            <Box>
                                <Stack direction="row" spacing={2.5} alignItems="center">
                                    <Box className="glass-container" sx={{ p: 1.5, bgcolor: '#1a103d', color: 'white', borderRadius: 4, display: 'flex' }}>
                                        <TerminalIcon sx={{ fontSize: 32 }} />
                                    </Box>
                                    <div>
                                        <Typography variant="h3" sx={{ fontWeight: 1000, letterSpacing: '-0.04em', color: '#1a103d' }}>
                                            Intake Observability
                                        </Typography>
                                        <Stack direction="row" spacing={1} alignItems="center">
                                            <div className="status-pulse" />
                                            <Typography variant="body2" sx={{ fontWeight: 700, opacity: 0.6 }}>
                                                Live Ingestion Pipeline Active
                                            </Typography>
                                        </Stack>
                                    </div>
                                </Stack>
                            </Box>

                            <Button
                                variant="contained"
                                onClick={() => fetchData()}
                                disabled={isRefreshing}
                                startIcon={isRefreshing ? <CircularProgress size={16} color="inherit" /> : <RefreshIcon />}
                                sx={{ 
                                    borderRadius: '14px', 
                                    px: 3, py: 1.2,
                                    bgcolor: '#1a103d',
                                    fontWeight: 800,
                                    textTransform: 'none',
                                    '&:hover': { bgcolor: '#2d1b6b' },
                                    boxShadow: '0 8px 16px rgba(26, 16, 61, 0.2)'
                                }}
                            >
                                Sync Reality
                            </Button>
                        </Stack>
                    </Box>

                    {/* Metric Grid - All cards equally distributed */}
                    <Grid container spacing={4} sx={{ mb: 8, justifyContent: 'center' }}>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Average Latency"
                                value={`${stats?.latencies?.processing_lag_avg_s?.toFixed(2)}s`}
                                icon={<TimerIcon />}
                                color={stats?.latencies?.processing_lag_avg_s > 30 ? 'danger' : 'primary'}
                                sparkline={[10, 20, 15, 25, 22, 30, 28, 35]} 
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Active Jobs"
                                value={stats?.queue_size || 0}
                                icon={<FlashOnIcon className={stats?.queue_size > 0 ? 'pulse-glow' : ''} />}
                                color="accent"
                                sparkline={[40, 35, 30, 45, 50, 40, 30, 20]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Processing Power"
                                value={`${stats?.latencies?.worker_time_avg_ms?.toFixed(0)}ms`}
                                icon={<FlashOnIcon />}
                                color="success"
                                sparkline={[100, 120, 110, 130, 125, 140, 135, 150]}
                            />
                        </Grid>
                        <Grid item xs={12} sm={6} md={3}>
                            <MetricCard
                                title="Failure Rate"
                                value={`${failRateValue.toFixed(2)}%`}
                                icon={<ErrorOutlineIcon />}
                                color={failRateValue > 1 ? 'danger' : 'success'}
                                sparkline={[5, 4, 3, 2, 1, 0, 0, 0]}
                            />
                        </Grid>
                    </Grid>

                    {/* Charts & Forensic Feed - Centered row */}
                    <Grid container spacing={4} sx={{ justifyContent: 'center' }}>
                        <Grid item xs={12} lg={8}>
                            <Paper className="glass-container" sx={{ p: 4, height: 500, overflow: 'hidden' }}>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 4 }}>
                                    <Typography variant="h6" sx={{ fontWeight: 900, letterSpacing: '0.05em' }}>
                                        PROCESSING LAG TREND
                                    </Typography>
                                    <Chip label="REAL-TIME" color="primary" size="small" sx={{ fontWeight: 900, borderRadius: 1.5 }} />
                                </Box>
                                <Box sx={{ height: 350 }}>
                                    <Line options={chartOptions} data={historyData} />
                                </Box>
                            </Paper>
                        </Grid>

                        <Grid item xs={12} lg={4}>
                            <Paper className="glass-container" sx={{ p: 4, height: 500, display: 'flex', flexDirection: 'column' }}>
                                <Typography variant="h6" sx={{ mb: 3, fontWeight: 900, display: 'flex', alignItems: 'center' }}>
                                    <TerminalIcon sx={{ mr: 1.5, color: '#4cc9f0' }} />
                                    FORENSIC FEED
                                </Typography>
                                
                                <Box sx={{ flexGrow: 1, overflowY: 'auto', px: 1 }}>
                                    <Stack spacing={2}>
                                        {recentLogs.map((log) => (
                                            <Box key={log.id} sx={{ 
                                                p: 2.5, 
                                                borderRadius: 4, 
                                                bgcolor: 'rgba(0,0,0,0.02)',
                                                borderLeft: `4px solid ${log.processing_status === 'processed' ? '#00e676' : log.processing_status === 'duplicate' ? '#4cc9f0' : '#ff1744'}`,
                                                transition: 'all 0.2s ease',
                                                '&:hover': { bgcolor: 'rgba(0,0,0,0.04)', transform: 'translateX(4px)' }
                                            }}>
                                                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 1 }}>
                                                    <Typography sx={{ fontSize: '0.75rem', fontWeight: 900, color: 'text.secondary' }}>
                                                        RECP: {log.receipt_no || '---'}
                                                    </Typography>
                                                    <Typography sx={{ fontSize: '0.65rem', fontWeight: 700, opacity: 0.5 }}>
                                                        {new Date(log.received_at).toLocaleTimeString()}
                                                    </Typography>
                                                </Stack>
                                                <Stack direction="row" spacing={1} alignItems="center">
                                                    {log.processing_status === 'duplicate' ? <GhostIcon sx={{ fontSize: 16, color: '#4cc9f0' }} /> : <CheckCircleIcon sx={{ fontSize: 16, color: '#00e676' }} />}
                                                    <Typography sx={{ fontSize: '0.8125rem', fontWeight: 800 }}>
                                                        {log.processing_status === 'duplicate' ? 'Ghost Hunter: Resolved' : 'Ingestion Success'}
                                                    </Typography>
                                                </Stack>
                                                {log.last_error_message && (
                                                    <Typography sx={{ mt: 1, fontSize: '0.7rem', color: 'error.main', fontStyle: 'italic', fontWeight: 600 }}>
                                                        {log.last_error_message}
                                                    </Typography>
                                                )}
                                            </Box>
                                        ))}
                                        {recentLogs.length === 0 && (
                                            <Typography sx={{ py: 4, textAlign: 'center', opacity: 0.5, fontStyle: 'italic' }}>
                                                No recent activity detected.
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
