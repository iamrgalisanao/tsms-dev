import React, { useState, useEffect, useCallback } from 'react';
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
    Button
} from '@mui/material';
import {
    Line,
    Bar
} from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';
import RefreshIcon from '@mui/icons-material/Refresh';
import TimerIcon from '@mui/icons-material/Timer';
import FlashOnIcon from '@mui/icons-material/FlashOn';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import GroupsIcon from '@mui/icons-material/Groups';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend
);

const IntakeHealthPage = () => {
    const [stats, setStats] = useState(null);
    const [history, setHistory] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);

    const fetchData = useCallback(async (isInitial = false) => {
        try {
            if (isInitial) setLoading(true);
            setIsRefreshing(true);

            const [statsRes, historyRes, tenantsRes] = await Promise.all([
                api.getIntakeMetrics(),
                api.getIntakeHistory('intake.processing_lag'),
                api.getTenantIntakeStats()
            ]);

            setStats(statsRes);
            setHistory(historyRes.data || []);
            setTenants(tenantsRes.data || []);
        } catch (error) {
            console.error('Error fetching intake health data:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchData(true);
        const interval = setInterval(fetchData, 15000); // Auto-refresh every 15s
        return () => clearInterval(interval);
    }, [fetchData]);

    if (loading && !stats) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '80vh' }}>
                <CircularProgress size={60} thickness={4} />
            </Box>
        );
    }

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false,
            },
            tooltip: {
                backgroundColor: 'rgba(0,0,0,0.8)',
                padding: 12,
                titleFont: { size: 14, weight: 'bold' },
                bodyFont: { size: 13 },
                displayColors: false,
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 10,
                    font: { size: 11, weight: 600 }
                }
            },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)' },
                ticks: { font: { size: 11, weight: 600 } }
            }
        }
    };

    const historyData = {
        labels: history.map(h => h.time.split(' ')[1]),
        datasets: [{
            label: 'Avg Lag (s)',
            data: history.map(h => h.value),
            borderColor: '#1976d2',
            backgroundColor: 'rgba(25, 118, 210, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 0,
        }]
    };

    return (
        <Box sx={{ pb: 10 }}>
            {/* Breadcrumbs */}
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>INTAKE OBSERVABILITY</Typography>
                </Breadcrumbs>

                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 6 }}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <FlashOnIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Intake Pipeline Health
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Real-time observability into the asynchronous ingestion engine.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Button
                        variant="outlined"
                        onClick={() => fetchData()}
                        disabled={isRefreshing}
                        startIcon={isRefreshing ? <CircularProgress size={20} /> : <RefreshIcon />}
                        sx={{ borderRadius: 3, textTransform: 'none', fontWeight: 700 }}
                    >
                        Sync Dashboard
                    </Button>
                </Stack>
            </Box>

            {/* Metric Cards */}
            <Grid container spacing={4} sx={{ mb: 8 }}>
                <Grid item xs={12} md={3}>
                    <MetricCard
                        title="Avg Intake Lag"
                        value={`${stats?.latencies?.processing_lag_avg_s?.toFixed(2)}s`}
                        icon={<TimerIcon />}
                        color={stats?.latencies?.processing_lag_avg_s > 30 ? 'danger' : 'success'}
                    />
                </Grid>
                <Grid item xs={12} md={3}>
                    <MetricCard
                        title="In-Flight Jobs"
                        value={stats?.queue_size || 0}
                        icon={<FlashOnIcon />}
                        color="primary"
                    />
                </Grid>
                <Grid item xs={12} md={3}>
                    <MetricCard
                        title="Processing Speed"
                        value={`${stats?.latencies?.worker_time_avg_ms?.toFixed(0)}ms`}
                        icon={<TimerIcon />}
                        color="accent"
                    />
                </Grid>
                <Grid item xs={12} md={3}>
                    <MetricCard
                        title="Fail Rate"
                        value={`${Math.min(100, (stats?.metrics?.['intake.failed_count'] / (stats?.metrics?.['intake.processed_count'] || 1)) * 100).toFixed(1)}%`}
                        icon={<ErrorOutlineIcon />}
                        color={stats?.metrics?.['intake.failed_count'] > 0 ? 'danger' : 'success'}
                        subtitle={stats?.metrics?.['intake.failed_count'] > 0 ? `${stats.metrics['intake.failed_count'].toLocaleString()} failed records` : 'All systems clear'}
                    />
                </Grid>
            </Grid>

            {/* Charts Section */}
            <Grid container spacing={4} sx={{ mb: 8 }}>
                <Grid item xs={12} lg={8}>
                    <Paper sx={{ p: 4, borderRadius: '32px', height: 400, boxShadow: '0 10px 30px rgba(0,0,0,0.03)' }}>
                        <Typography variant="h6" sx={{ mb: 4, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                            Processing Lag (Last Hour)
                        </Typography>
                        <Box sx={{ height: 300 }}>
                            <Line options={chartOptions} data={historyData} />
                        </Box>
                    </Paper>
                </Grid>
                <Grid item xs={12} lg={4}>
                    <Paper sx={{ p: 4, borderRadius: '32px', height: 400, boxShadow: '0 10px 30px rgba(0,0,0,0.03)', overflow: 'hidden' }}>
                        <Typography variant="h6" sx={{ mb: 4, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.05em', display: 'flex', alignItems: 'center' }}>
                            <GroupsIcon sx={{ mr: 1 }} />
                            Top Tenants
                        </Typography>
                        <TableContainer sx={{ height: 280 }}>
                            <Table stickyHeader size="small">
                                <TableHead>
                                    <TableRow>
                                        <TableCell sx={{ fontWeight: 800, color: 'text.secondary' }}>Tenant ID</TableCell>
                                        <TableCell align="right" sx={{ fontWeight: 800, color: 'text.secondary' }}>Volume</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {tenants.map((row) => (
                                        <TableRow key={row.tenant_id} hover>
                                            <TableCell sx={{ fontWeight: 700, fontSize: '0.875rem' }}>{row.tenant_id}</TableCell>
                                            <TableCell align="right" sx={{ fontWeight: 900, color: 'primary.main' }}>
                                                {row.count.toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {tenants.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={2} align="center" sx={{ py: 4, color: 'text.secondary', fontStyle: 'italic' }}>
                                                No active telemetry for this period.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </TableContainer>
                    </Paper>
                </Grid>
            </Grid>
        </Box>
    );
};

export default IntakeHealthPage;
