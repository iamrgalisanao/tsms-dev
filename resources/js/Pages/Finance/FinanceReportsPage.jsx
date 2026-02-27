import React, { useState, useEffect, useCallback } from 'react';
import {
    Box,
    Typography,
    Grid,
    Button,
    CircularProgress,
    Stack,
    Breadcrumbs,
    Link as MuiLink,
    TextField,
    MenuItem,
    Card,
    CardHeader,
    CardContent,
    Autocomplete
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import DescriptionIcon from '@mui/icons-material/Description';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import FilterAltIcon from '@mui/icons-material/FilterAlt';

import TransactionChart from '../../Components/dashboard/TransactionChart';
import axios from 'axios';

const FinanceReportsPage = () => {
    const [tenants, setTenants] = useState([]);
    const [selectedTenant, setSelectedTenant] = useState(null);
    const [dateRange, setDateRange] = useState({
        start: new Date().toISOString().split('T')[0],
        end: new Date().toISOString().split('T')[0]
    });
    const [loading, setLoading] = useState(false);
    const [charts, setCharts] = useState({
        basketSize: null,
        weeklySales: null,
        monthlyIncome: null,
        l2Small: null,
        l1Large: null,
        l2Large: null
    });

    const fetchTenants = useCallback(async () => {
        try {
            const resp = await axios.get('/commercial/reports/tenants');
            setTenants(resp.data || []);
        } catch (error) {
            console.error('Error fetching tenants:', error);
        }
    }, []);

    const fetchReportData = useCallback(async () => {
        if (!selectedTenant) return;

        setLoading(true);
        try {
            // In a real implementation, we would call the specialized endpoints
            // For now, we'll simulate the data fetching logic as seen in index.blade.php
            // and adapt it to the common TransactionChart component

            // This is a placeholder for the actual API calls to /api/v1/webapp/reports/...
            // which will be hooked up to the FinanceCalculationService on the backend

            const labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const sampleSeries = [120, 150, 130, 160, 170, 180, 200, 190, 220, 260, 300, 280];

            setCharts({
                basketSize: {
                    labels,
                    sales: sampleSeries,
                    volume: sampleSeries.map(s => s / 10)
                },
                weeklySales: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    sales: [0.2, 0.4, 0.5, 0.6, 0.7, 0.5, 0.3],
                    volume: [20, 40, 50, 60, 70, 50, 30]
                },
                monthlyIncome: {
                    labels,
                    sales: sampleSeries.map(v => v * 10),
                    volume: sampleSeries
                },
                l2Small: {
                    labels,
                    sales: sampleSeries.map(v => Math.round(v * 0.5)),
                    volume: sampleSeries.map(v => Math.round(v * 0.05))
                },
                l1Large: {
                    labels,
                    sales: sampleSeries.map(v => Math.round(v * 0.8)),
                    volume: sampleSeries.map(v => Math.round(v * 0.08))
                },
                l2Large: {
                    labels,
                    sales: sampleSeries.map(v => Math.round(v * 0.3)),
                    volume: sampleSeries.map(v => Math.round(v * 0.03))
                }
            });
        } catch (error) {
            console.error('Error fetching report data:', error);
        } finally {
            setLoading(false);
        }
    }, [selectedTenant, dateRange]);

    useEffect(() => {
        fetchTenants();
    }, [fetchTenants]);

    useEffect(() => {
        if (selectedTenant) {
            fetchReportData();
        }
    }, [selectedTenant, fetchReportData]);

    const handleExport = () => {
        if (!selectedTenant) return;
        const [year, month] = dateRange.start.split('-');
        window.open(`/finance/reports/export?year=${year}&month=${month}&tenant=${selectedTenant.id}`, '_blank');
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
                        FINANCE
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>REPORTS GENERATOR</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', lg: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', lg: 'center' }} sx={{ mb: 6 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(29, 67, 155, 0.25)' }}>
                                <DescriptionIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Financial Reports
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Generate detailed analytics and export fiscal data.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Button
                        variant="contained"
                        onClick={handleExport}
                        disabled={!selectedTenant || loading}
                        startIcon={<FileDownloadIcon />}
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
                        Export to Excel
                    </Button>
                </Stack>
            </Box>

            {/* Filter Section */}
            <Box className="glass-card" sx={{ p: 4, mb: 6, borderRadius: '24px', border: '1px solid rgba(255,255,255,0.4)', boxShadow: '0 8px 32px rgba(0,0,0,0.05)' }}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={3} alignItems="center">
                    <Stack direction="row" spacing={2} alignItems="center" sx={{ color: 'primary.main' }}>
                        <FilterAltIcon />
                        <Typography variant="subtitle2" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em' }}>Filter Command</Typography>
                    </Stack>

                    <Autocomplete
                        options={tenants}
                        getOptionLabel={(option) => option.trade_name}
                        value={selectedTenant}
                        onChange={(event, newValue) => setSelectedTenant(newValue)}
                        sx={{ minWidth: 300 }}
                        renderInput={(params) => (
                            <TextField {...params} label="Select Tenant / Business Unit" variant="outlined" size="small" />
                        )}
                    />

                    <TextField
                        type="date"
                        label="Report Start Date"
                        value={dateRange.start}
                        onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
                        size="small"
                        InputLabelProps={{ shrink: true }}
                    />

                    <TextField
                        type="date"
                        label="Report End Date"
                        value={dateRange.end}
                        onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
                        size="small"
                        InputLabelProps={{ shrink: true }}
                    />
                </Stack>
            </Box>

            {!selectedTenant ? (
                <Box sx={{ py: 10, textAlign: 'center', bgcolor: 'white', borderRadius: '32px', border: '1px dashed', borderColor: 'divider' }}>
                    <Typography variant="h6" sx={{ color: 'text.secondary', fontWeight: 600 }}>
                        Please select a tenant to generate insights.
                    </Typography>
                </Box>
            ) : (
                <Grid container spacing={4}>
                    {[
                        { title: 'Basket Size (kPhp)', key: 'basketSize' },
                        { title: 'Weekly Sales (M Php)', key: 'weeklySales' },
                        { title: 'Monthly Income (Category)', key: 'monthlyIncome' },
                        { title: 'L2 <21SQM Income/SQM', key: 'l2Small' },
                        { title: 'L1 >21SQM Income/SQM', key: 'l1Large' },
                        { title: 'L2 >21SQM Income/SQM', key: 'l2Large' },
                    ].map((item) => (
                        <Grid item xs={12} md={6} lg={4} key={item.key}>
                            <Card sx={{ borderRadius: '24px', height: '100%', border: '1px solid rgba(0,0,0,0.05)', boxShadow: '0 4px 12px rgba(0,0,0,0.03)', transition: 'transform 0.2s', '&:hover': { transform: 'translateY(-4px)' } }}>
                                <CardHeader
                                    title={item.title}
                                    titleTypographyProps={{ variant: 'subtitle1', fontWeight: 900, color: 'white' }}
                                    sx={{ bgcolor: 'secondary.main', py: 1.5 }}
                                />
                                <CardContent sx={{ h: 250 }}>
                                    <TransactionChart data={charts[item.key]} loading={loading} />
                                </CardContent>
                            </Card>
                        </Grid>
                    ))}
                </Grid>
            )}
        </Box>
    );
};

export default FinanceReportsPage;
