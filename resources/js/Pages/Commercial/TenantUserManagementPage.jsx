import React, { useState } from 'react';
import {
    Container,
    Paper,
    Typography,
    Box,
    TextField,
    Button,
    Grid,
    Alert
} from '@mui/material';
import { useNavigate } from 'react-router-dom';

// Role check utility (stub, replace with real auth logic)
const useRole = () => {
    // Replace with actual role check from auth context or API
    // Example: return ['admin', 'commercial'].includes(user.role);
    const user = { role: 'admin' }; // Stub
    return user.role === 'admin' || user.role === 'commercial';
};

const TenantUserManagementPage = () => {
    const navigate = useNavigate();
    const isAuthorized = useRole();
    const [form, setForm] = useState({
        trade_name: '',
        customer_code: '',
        company_name: '',
        level: '',
        unit_no: '',
        category: '',
        lease_expiry: '',
    });
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [loading, setLoading] = useState(false);

    if (!isAuthorized) {
        return (
            <Container sx={{ py: 4 }}>
                <Alert severity="error">Access denied. Only admin and commercial roles can manage tenants.</Alert>
            </Container>
        );
    }

    const handleChange = (e) => {
        setForm({ ...form, [e.target.name]: e.target.value });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError(null);
        setSuccess(null);
        setLoading(true);
        // Basic validation
        if (!form.trade_name || !form.customer_code || !form.company_name) {
            setError('Trade Name, Customer Code, and Company Name are required.');
            setLoading(false);
            return;
        }
        try {
            // Replace with actual API endpoint
            await fetch('/api/tenants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(form)
            });
            setSuccess('Tenant added successfully!');
            setForm({
                trade_name: '',
                customer_code: '',
                company_name: '',
                level: '',
                unit_no: '',
                category: '',
                lease_expiry: '',
            });
        } catch (err) {
            setError('Failed to add tenant.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Container maxWidth="sm" sx={{ py: 4 }}>
            <Paper sx={{ p: 4, borderRadius: 3 }}>
                <Typography variant="h5" fontWeight="bold" gutterBottom>
                    Tenant User Management
                </Typography>
                <Box component="form" onSubmit={handleSubmit}>
                    <Grid container spacing={2}>
                        <Grid item xs={12}>
                            <TextField
                                label="Trade Name"
                                name="trade_name"
                                value={form.trade_name}
                                onChange={handleChange}
                                required
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={12}>
                            <TextField
                                label="Customer Code"
                                name="customer_code"
                                value={form.customer_code}
                                onChange={handleChange}
                                required
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={12}>
                            <TextField
                                label="Company Name"
                                name="company_name"
                                value={form.company_name}
                                onChange={handleChange}
                                required
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={6}>
                            <TextField
                                label="Level"
                                name="level"
                                value={form.level}
                                onChange={handleChange}
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={6}>
                            <TextField
                                label="Unit No"
                                name="unit_no"
                                value={form.unit_no}
                                onChange={handleChange}
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={12}>
                            <TextField
                                label="Category"
                                name="category"
                                value={form.category}
                                onChange={handleChange}
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={12}>
                            <TextField
                                label="Lease Expiry"
                                name="lease_expiry"
                                value={form.lease_expiry}
                                onChange={handleChange}
                                type="date"
                                InputLabelProps={{ shrink: true }}
                                fullWidth
                            />
                        </Grid>
                        <Grid item xs={12}>
                            <Button
                                type="submit"
                                variant="contained"
                                color="primary"
                                disabled={loading}
                                fullWidth
                            >
                                {loading ? 'Adding...' : 'Add Tenant'}
                            </Button>
                        </Grid>
                    </Grid>
                    {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}
                    {success && <Alert severity="success" sx={{ mt: 2 }}>{success}</Alert>}
                </Box>
            </Paper>
        </Container>
    );
};

export default TenantUserManagementPage;
