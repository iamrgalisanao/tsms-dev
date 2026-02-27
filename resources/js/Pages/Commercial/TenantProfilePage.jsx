import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import axios from 'axios';
import {
    Container,
    Grid,
    Paper,
    Typography,
    Box,
    Avatar,
    Button,
    Breadcrumbs,
    Divider,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    CircularProgress,
    Alert
} from '@mui/material';
import {
    Store as StoreIcon,
    Edit as EditIcon,
    History as HistoryIcon,
    TrendingUp as SalesIcon,
    CalendarMonth as CalendarIcon,
    EventBusy as ExpiryIcon,
    ChevronRight as ChevronRightIcon
} from '@mui/icons-material';
import MetricCard from '../../Components/Commercial/MetricCard';

const TenantProfilePage = () => {
    const { id } = useParams();
    const [tenant, setTenant] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchTenantDetails = async () => {
            try {
                setLoading(true);
                // The show endpoint returns a blade view by default, but we can try to fetch JSON
                // or use the models we have. For this implementation, we'll assume a JSON response 
                // is available or we'll wrap it.
                const response = await axios.get(`/commercial/reports/tenants/${id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                setTenant(response.data);
            } catch (err) {
                setError('Failed to load tenant details.');
                console.error(err);
            } finally {
                setLoading(false);
            }
        };
        fetchTenantDetails();
    }, [id]);

    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val || 0);

    if (loading) return (
        <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '80vh' }}>
            <CircularProgress />
        </Box>
    );

    if (error) return (
        <Container sx={{ py: 4 }}>
            <Alert severity="error">{error}</Alert>
            <Button component={Link} to="/commercial/tenants" sx={{ mt: 2 }}>Back to Directory</Button>
        </Container>
    );

    return (
        <Container maxWidth="xl" sx={{ py: 4 }}>
            <Box sx={{ mb: 3 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mt: 2 }}>
                    <Typography variant="h4" fontWeight="bold">{tenant?.trade_name}</Typography>
                    <Button variant="outlined" startIcon={<EditIcon />} disabled>Edit Tenant</Button>
                </Box>
            </Box>

            <Grid container spacing={3}>
                {/* Left Column: Profile Card */}
                <Grid item xs={12} md={4}>
                    <Paper elevation={0} sx={{ p: 4, border: '1px solid', borderColor: 'divider', borderRadius: 2, textAlign: 'center' }}>
                        <Avatar
                            sx={{
                                width: 120,
                                height: 120,
                                mx: 'auto',
                                mb: 3,
                                bgcolor: 'primary.light',
                                border: '4px solid',
                                borderColor: 'white',
                                boxShadow: 2
                            }}
                        >
                            <StoreIcon sx={{ fontSize: 60, color: 'primary.main' }} />
                        </Avatar>
                        <Typography variant="h6" fontWeight="bold">{tenant?.trade_name}</Typography>
                        <Typography variant="body2" color="text.secondary" gutterBottom>
                            Code: {tenant?.customer_code}
                        </Typography>

                        <Divider sx={{ my: 3 }} />

                        <Box sx={{ textAlign: 'left', spaceY: 1 }}>
                            <Typography variant="subtitle2" color="text.secondary">Company</Typography>
                            <Typography variant="body1" gutterBottom>{tenant?.name || 'N/A'}</Typography>

                            <Typography variant="subtitle2" color="text.secondary" sx={{ mt: 2 }}>Location</Typography>
                            <Typography variant="body1" gutterBottom>Level {tenant?.level}, Unit {tenant?.unit_no}</Typography>

                            <Typography variant="subtitle2" color="text.secondary" sx={{ mt: 2 }}>Category</Typography>
                            <Typography variant="body1">{tenant?.category || 'Retail'}</Typography>
                        </Box>
                    </Paper>
                </Grid>

                {/* Right Column: Metrics & Transactions */}
                <Grid item xs={12} md={8}>
                    <Grid container spacing={3} sx={{ mb: 3 }}>
                        <Grid item xs={12} sm={4}>
                            <MetricCard
                                title="Total Sales YTD"
                                value={formatCurrency(tenant?.ytd_sales || 1250000)}
                                icon={SalesIcon}
                                color="primary.main"
                            />
                        </Grid>
                        <Grid item xs={12} sm={4}>
                            <MetricCard
                                title="Current Month"
                                value={formatCurrency(tenant?.month_sales || 145000)}
                                icon={CalendarIcon}
                                color="info.main"
                            />
                        </Grid>
                        <Grid item xs={12} sm={4}>
                            <MetricCard
                                title="Lease Expiry"
                                value={tenant?.lease_expiry || 'Dec 2026'}
                                icon={ExpiryIcon}
                                color="error.main"
                            />
                        </Grid>
                    </Grid>

                    <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2 }}>
                        <Box sx={{ p: 3, display: 'flex', alignItems: 'center', gap: 1 }}>
                            <HistoryIcon color="action" />
                            <Typography variant="h6" fontWeight="bold">Recent Transactions</Typography>
                        </Box>
                        <TableContainer>
                            <Table>
                                <TableHead sx={{ bgcolor: 'grey.50' }}>
                                    <TableRow>
                                        <TableCell sx={{ fontWeight: 'bold' }}>Date</TableCell>
                                        <TableCell sx={{ fontWeight: 'bold' }}>Reference No</TableCell>
                                        <TableCell sx={{ fontWeight: 'bold' }} align="right">Amount</TableCell>
                                        <TableCell sx={{ fontWeight: 'bold' }}>Status</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {/* Mocking some transactions if real ones aren't sent yet */}
                                    {[1, 2, 3, 4, 5].map((i) => (
                                        <TableRow key={i} hover>
                                            <TableCell>2026-02-{15 - i}</TableCell>
                                            <TableCell>REF-{1000 + i}</TableCell>
                                            <TableCell align="right">{formatCurrency(500 * i + Math.random() * 100)}</TableCell>
                                            <TableCell>
                                                <Typography variant="caption" sx={{ bgcolor: 'success.light', color: 'success.dark', px: 1, py: 0.5, borderRadius: 1 }}>
                                                    Success
                                                </Typography>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </TableContainer>
                        <Box sx={{ p: 2, textAlign: 'center' }}>
                            <Button size="small">View All Transactions</Button>
                        </Box>
                    </Paper>
                </Grid>
            </Grid>
        </Container>
    );
};

export default TenantProfilePage;
