import React, { useState, useEffect, useCallback } from 'react';
import {
    Box,
    Typography,
    Grid,
    Button,
    CircularProgress,
    Stack,
    Breadcrumbs,
    Link as MuiLink
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import DashboardIcon from '@mui/icons-material/Dashboard';
import RefreshIcon from '@mui/icons-material/Refresh';
import SyncIcon from '@mui/icons-material/Sync';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import TimelineIcon from '@mui/icons-material/Timeline';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import HubIcon from '@mui/icons-material/Hub';
import AnalyticsIcon from '@mui/icons-material/Analytics';

import MetricCard from '../../Components/Commercial/MetricCard';
import TransactionChart from '../../Components/dashboard/TransactionChart';
import axios from 'axios';

const FinanceDashboardPage = () => {
    const [metrics, setMetrics] = useState({
        today_gross: 0,
        this_week_total: 0,
        this_month_total: 0,
        this_year_total: 0
    });
    const [charts, setCharts] = useState({
        daily: null,
        weekly: null,
        monthly: null,
        yearly: null
    });
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);

    const fetchData = useCallback(async () => {
        setRefreshing(true);
        try {
            // Fetch from commercial proxy endpoints as they aggregate data for all tenants
            // This matches the current Blade finance dashboard behavior
            const [dailyResp, weeklyResp, monthlyResp, yearlyResp] = await Promise.all([
                axios.get('/commercial/reports/transactions/daily'),
                axios.get('/commercial/reports/transactions/weekly'),
                axios.get('/commercial/reports/transactions/monthly'),
                axios.get('/commercial/reports/transactions/yearly')
            ]);

            const todaySum = dailyResp.data?.summary?.gross_sales || 0;
            const weekTotal = (weeklyResp.data?.days || []).reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);
            const monthTotal = (monthlyResp.data?.days || []).reduce((acc, d) => acc + Number(d.gross_sales || 0), 0);
            const yearTotal = (yearlyResp.data?.months || []).reduce((acc, m) => acc + Number(m.gross_sales || 0), 0);

            setMetrics({
                today_gross: todaySum,
                this_week_total: weekTotal,
                this_month_total: monthTotal,
                this_year_total: yearTotal
            });

            setCharts({
                daily: {
                    labels: (dailyResp.data?.hours || []).map(h => h.hour),
                    sales: (dailyResp.data?.hours || []).map(h => h.gross_sales),
                    volume: (dailyResp.data?.hours || []).map(h => h.transaction_count)
                },
                weekly: {
                    labels: (weeklyResp.data?.days || []).map(d => d.date),
                    sales: (weeklyResp.data?.days || []).map(d => d.gross_sales),
                    volume: (weeklyResp.data?.days || []).map(d => d.transaction_count)
                },
                monthly: {
                    labels: (monthlyResp.data?.days || []).map(d => d.date),
                    sales: (monthlyResp.data?.days || []).map(d => d.gross_sales),
                    volume: (monthlyResp.data?.days || []).map(d => d.transaction_count)
                },
                yearly: {
                    labels: (yearlyResp.data?.months || []).map(m => m.month),
                    sales: (yearlyResp.data?.months || []).map(m => m.gross_sales),
                    volume: (yearlyResp.data?.months || []).map(m => m.transaction_count)
                }
            });

        } catch (error) {
            console.error('Error fetching finance dashboard data:', error);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);

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
                        FINANCE
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>DASHBOARD COMMAND</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', lg: 'center' }} sx={{ mb: 6 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'secondary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(235, 52, 46, 0.25)' }}>
                                <DashboardIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Finance Command
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Aggregated ecosystem performance and financial trajectory.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Button
                        variant="contained"
                        onClick={fetchData}
                        disabled={refreshing}
                        startIcon={refreshing ? <CircularProgress size={20} color="inherit" /> : <SyncIcon />}
                        className="pitx-gradient"
                        sx={{
                            borderRadius: '16px',
                            px: 4,
                            py: 1.5,
                            fontWeight: 900,
                            fontSize: '0.75rem',
                            letterSpacing: '0.1em',
                            textTransform: 'uppercase',
                            color: 'white',
                            boxShadow: '0 8px 25px rgba(29, 67, 155, 0.25)',
                            '&:hover': { opacity: 0.9 }
                        }}
                    >
                        {refreshing ? 'Syncing Ecosystem...' : 'Force Sync'}
                    </Button>
                </Stack>
            </Box>

            {/* Metrics Grid */}
            <Grid container spacing={4} sx={{ mb: 8 }}>
                <Grid item xs={12} sm={6} lg={3}>
                    <MetricCard
                        title="Today's Performance"
                        value={formatCurrency(metrics.today_gross)}
                        icon="today"
                        subtitle="Live Gross Sales"
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <MetricCard
                        title="Active Week"
                        value={formatCurrency(metrics.this_week_total)}
                        icon="date_range"
                        subtitle="Weekly Velocity"
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <MetricCard
                        title="Current Month"
                        value={formatCurrency(metrics.this_month_total)}
                        icon="calendar_month"
                        subtitle="Target Tracking"
                    />
                </Grid>
                <Grid item xs={12} sm={6} lg={3}>
                    <MetricCard
                        title="Annual Aggregate"
                        value={formatCurrency(metrics.this_year_total)}
                        icon="history"
                        subtitle="Year-to-Date"
                    />
                </Grid>
            </Grid>

            {/* Charts Grid */}
            <Grid container spacing={4}>
                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', position: 'relative', overflow: 'hidden' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Daily Performance</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>24h sales stream</Typography>
                            </Box>
                            <QueryStatsIcon sx={{ color: 'divider' }} />
                        </Stack>
                        <Box sx={{ h: 350, position: 'relative', zIndex: 1 }}>
                            <TransactionChart data={charts.daily} loading={loading} />
                        </Box>
                        <Box sx={{ position: 'absolute', bottom: -40, right: -40, opacity: 0.05, transform: 'rotate(-15deg)', pointerEvents: 'none' }}>
                            <TrendingUpIcon sx={{ fontSize: 200 }} />
                        </Box>
                    </Box>
                </Grid>

                <Grid item xs={12} lg={6}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', position: 'relative', overflow: 'hidden' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Weekly Lifecycle</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>7-day traffic trends</Typography>
                            </Box>
                            <TimelineIcon sx={{ color: 'divider' }} />
                        </Stack>
                        <Box sx={{ h: 350, position: 'relative', zIndex: 1 }}>
                            <TransactionChart data={charts.weekly} loading={loading} />
                        </Box>
                        <Box sx={{ position: 'absolute', bottom: -40, right: -40, opacity: 0.05, transform: 'rotate(-15deg)', pointerEvents: 'none' }}>
                            <HubIcon sx={{ fontSize: 200 }} />
                        </Box>
                    </Box>
                </Grid>

                <Grid item xs={12}>
                    <Box className="glass-card" sx={{ p: 4, borderRadius: '32px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 20px 40px rgba(0,0,0,0.05)', position: 'relative', overflow: 'hidden' }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4, position: 'relative', zIndex: 1 }}>
                            <Box>
                                <Typography variant="h6" sx={{ fontWeight: 900, color: 'text.primary', tracking: '-0.02em' }}>Strategic Growth</Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.1em', opacity: 0.6 }}>Long-term monthly trajectory</Typography>
                            </Box>
                            <Box sx={{ px: 2, py: 0.5, bgcolor: 'success.light', borderRadius: 2, color: 'success.dark', fontWeight: 900, fontSize: '0.65rem', textTransform: 'uppercase', letterSpacing: '0.1em', display: 'flex', alignItems: 'center', gap: 1 }}>
                                <Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: 'success.main' }} />
                                Positive Trend
                            </Box>
                        </Stack>
                        <Box sx={{ h: 400, position: 'relative', zIndex: 1 }}>
                            <TransactionChart data={charts.monthly} loading={loading} />
                        </Box>
                        <Box sx={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)', opacity: 0.02, pointerEvents: 'none' }}>
                            <AnalyticsIcon sx={{ fontSize: 500 }} />
                        </Box>
                    </Box>
                </Grid>
            </Grid>
        </Box>
    );
};

export default FinanceDashboardPage;
