import React, { useState, useEffect, useCallback } from 'react';
import {
    Container,
    Paper,
    Typography,
    Box,
    TextField,
    Button,
    Grid,
    Alert,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    IconButton,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Stack,
    Chip,
    CircularProgress,
    Divider,
    Tooltip
} from '@mui/material';
import {
    Add as AddIcon,
    Edit as EditIcon,
    Delete as DeleteIcon,
    Group as GroupIcon,
    Business as BusinessIcon,
    PersonAdd as PersonAddIcon,
    Refresh as RefreshIcon
} from '@mui/icons-material';
import api from '../../services/api';
import { useRole } from '../../Hooks/useRole';

const TenantUserManagementPage = () => {
    const isAuthorized = useRole();
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);

    // Dialog States
    const [tenantDialogOpen, setTenantDialogOpen] = useState(false);
    const [userDialogOpen, setUserDialogOpen] = useState(false);
    const [selectedTenant, setSelectedTenant] = useState(null);
    const [tenantUsers, setTenantUsers] = useState([]);
    const [usersLoading, setUsersLoading] = useState(false);

    // Form States
    const [tenantForm, setTenantForm] = useState({
        trade_name: '',
        customer_code: '',
        company_id: 1, // Default for now
        location_type: '',
        location: '',
        unit_no: '',
        category: '',
        status: 'Operational'
    });

    const [userForm, setUserForm] = useState({
        name: '',
        email: '',
        password: ''
    });

    const fetchTenants = useCallback(async () => {
        try {
            setLoading(true);
            const data = await api.getTenants();
            setTenants(data);
        } catch (err) {
            setError('Failed to fetch tenants.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (isAuthorized) {
            fetchTenants();
        }
    }, [isAuthorized, fetchTenants]);

    if (!isAuthorized) {
        return (
            <Container sx={{ py: 4 }}>
                <Alert severity="error">Access denied. Only admin and commercial roles can manage tenants.</Alert>
            </Container>
        );
    }

    const handleTenantSubmit = async (e) => {
        e.preventDefault();
        try {
            if (selectedTenant && !userDialogOpen) {
                await api.updateTenant(selectedTenant.id, tenantForm);
                setSuccess('Tenant updated successfully!');
            } else {
                await api.createTenant(tenantForm);
                setSuccess('Tenant created successfully!');
            }
            setTenantDialogOpen(false);
            fetchTenants();
        } catch (err) {
            setError(err.response?.data?.message || 'Operation failed.');
        }
    };

    const handleUserSubmit = async (e) => {
        e.preventDefault();
        try {
            await api.createTenantUser(selectedTenant.id, userForm);
            setSuccess('User added successfully!');
            setUserDialogOpen(false);
            setUserForm({ name: '', email: '', password: '' });
            handleManageUsers(selectedTenant);
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to add user.');
        }
    };

    const handleManageUsers = async (tenant) => {
        setSelectedTenant(tenant);
        setUsersLoading(true);
        setUserDialogOpen(true);
        try {
            const users = await api.getTenantUsers(tenant.id);
            setTenantUsers(users);
        } catch (err) {
            setError('Failed to fetch tenant users.');
        } finally {
            setUsersLoading(false);
        }
    };

    const handleDeleteTenant = async (id) => {
        if (window.confirm('Are you sure you want to delete this tenant?')) {
            try {
                await api.deleteTenant(id);
                setSuccess('Tenant deleted successfully!');
                fetchTenants();
            } catch (err) {
                setError('Failed to delete tenant.');
            }
        }
    };

    const handleDeleteUser = async (userId) => {
        if (window.confirm('Are you sure you want to remove this user?')) {
            try {
                await api.deleteTenantUser(selectedTenant.id, userId);
                handleManageUsers(selectedTenant);
            } catch (err) {
                setError('Failed to delete user.');
            }
        }
    };

    return (
        <Container maxWidth="lg" sx={{ py: 4 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                <Box>
                    <Typography variant="h4" fontWeight="bold" sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                        <BusinessIcon color="primary" fontSize="large" />
                        Tenant Management
                    </Typography>
                    <Typography color="text.secondary">Manage business entities and their associated users.</Typography>
                </Box>
                <Stack direction="row" spacing={2}>
                    <Button
                        variant="outlined"
                        startIcon={<RefreshIcon />}
                        onClick={fetchTenants}
                    >
                        Sync
                    </Button>
                    <Button
                        variant="contained"
                        startIcon={<AddIcon />}
                        onClick={() => {
                            setSelectedTenant(null);
                            setTenantForm({
                                trade_name: '',
                                customer_code: '',
                                company_id: 1,
                                location_type: '',
                                location: '',
                                unit_no: '',
                                category: '',
                                status: 'Operational'
                            });
                            setTenantDialogOpen(true);
                        }}
                    >
                        New Tenant
                    </Button>
                </Stack>
            </Stack>

            {error && <Alert severity="error" sx={{ mb: 3 }} onClose={() => setError(null)}>{error}</Alert>}
            {success && <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccess(null)}>{success}</Alert>}

            <TableContainer component={Paper} sx={{ borderRadius: 3, boxShadow: '0 4px 20px rgba(0,0,0,0.05)' }}>
                <Table>
                    <TableHead sx={{ bgcolor: 'grey.50' }}>
                        <TableRow>
                            <TableCell sx={{ fontWeight: 'bold' }}>Trade Name</TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>Code</TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>Category</TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>Users</TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>Terminals</TableCell>
                            <TableCell sx={{ fontWeight: 'bold' }}>Status</TableCell>
                            <TableCell align="right" sx={{ fontWeight: 'bold' }}>Actions</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {loading ? (
                            <TableRow>
                                <TableCell colSpan={7} align="center" sx={{ py: 3 }}>
                                    <CircularProgress size={24} />
                                </TableCell>
                            </TableRow>
                        ) : tenants.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={7} align="center" sx={{ py: 3 }}>
                                    No tenants found.
                                </TableCell>
                            </TableRow>
                        ) : tenants.map((tenant) => (
                            <TableRow key={tenant.id} hover>
                                <TableCell fontWeight="600">{tenant.trade_name}</TableCell>
                                <TableCell><code>{tenant.customer_code}</code></TableCell>
                                <TableCell>{tenant.category || 'N/A'}</TableCell>
                                <TableCell>
                                    <Chip
                                        size="small"
                                        label={tenant.users_count || 0}
                                        icon={<GroupIcon sx={{ fontSize: '14px !important' }} />}
                                    />
                                </TableCell>
                                <TableCell>{tenant.pos_terminals_count || 0}</TableCell>
                                <TableCell>
                                    <Chip
                                        size="small"
                                        label={tenant.status}
                                        color={tenant.status === 'Operational' ? 'success' : 'warning'}
                                        variant="outlined"
                                    />
                                </TableCell>
                                <TableCell align="right">
                                    <Tooltip title="Manage Users">
                                        <IconButton onClick={() => handleManageUsers(tenant)} color="primary">
                                            <PersonAddIcon />
                                        </IconButton>
                                    </Tooltip>
                                    <Tooltip title="Edit Tenant">
                                        <IconButton onClick={() => {
                                            setSelectedTenant(tenant);
                                            setTenantForm(tenant);
                                            setTenantDialogOpen(true);
                                        }}>
                                            <EditIcon />
                                        </IconButton>
                                    </Tooltip>
                                    <Tooltip title="Delete">
                                        <IconButton onClick={() => handleDeleteTenant(tenant.id)} color="error">
                                            <DeleteIcon />
                                        </IconButton>
                                    </Tooltip>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TableContainer>

            {/* Tenant Dialog */}
            <Dialog open={tenantDialogOpen} onClose={() => setTenantDialogOpen(false)} maxWidth="sm" fullWidth>
                <form onSubmit={handleTenantSubmit}>
                    <DialogTitle>{selectedTenant ? 'Edit Tenant' : 'Add New Tenant'}</DialogTitle>
                    <DialogContent dividers>
                        <Grid container spacing={2} sx={{ mt: 1 }}>
                            <Grid item xs={12}>
                                <TextField
                                    fullWidth
                                    label="Trade Name"
                                    required
                                    value={tenantForm.trade_name}
                                    onChange={(e) => setTenantForm({ ...tenantForm, trade_name: e.target.value })}
                                />
                            </Grid>
                            <Grid item xs={6}>
                                <TextField
                                    fullWidth
                                    label="Customer Code"
                                    required
                                    value={tenantForm.customer_code}
                                    onChange={(e) => setTenantForm({ ...tenantForm, customer_code: e.target.value })}
                                />
                            </Grid>
                            <Grid item xs={6}>
                                <TextField
                                    fullWidth
                                    select
                                    label="Status"
                                    SelectProps={{ native: true }}
                                    value={tenantForm.status}
                                    onChange={(e) => setTenantForm({ ...tenantForm, status: e.target.value })}
                                >
                                    <option value="Operational">Operational</option>
                                    <option value="Closed">Closed</option>
                                    <option value="Pending">Pending</option>
                                </TextField>
                            </Grid>
                            <Grid item xs={12}>
                                <TextField
                                    fullWidth
                                    label="Category"
                                    value={tenantForm.category}
                                    onChange={(e) => setTenantForm({ ...tenantForm, category: e.target.value })}
                                />
                            </Grid>
                            <Grid item xs={6}>
                                <TextField
                                    fullWidth
                                    label="Level"
                                    value={tenantForm.location_type}
                                    onChange={(e) => setTenantForm({ ...tenantForm, location_type: e.target.value })}
                                />
                            </Grid>
                            <Grid item xs={6}>
                                <TextField
                                    fullWidth
                                    label="Unit No"
                                    value={tenantForm.unit_no}
                                    onChange={(e) => setTenantForm({ ...tenantForm, unit_no: e.target.value })}
                                />
                            </Grid>
                        </Grid>
                    </DialogContent>
                    <DialogActions sx={{ p: 2 }}>
                        <Button onClick={() => setTenantDialogOpen(false)}>Cancel</Button>
                        <Button type="submit" variant="contained">Save Tenant</Button>
                    </DialogActions>
                </form>
            </Dialog>

            {/* User Management Dialog */}
            <Dialog open={userDialogOpen} onClose={() => setUserDialogOpen(false)} maxWidth="md" fullWidth>
                <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    Users for {selectedTenant?.trade_name}
                    <Button startIcon={<AddIcon />} variant="outlined" size="small" onClick={() => {
                        // Toggle sub-form or nested dialog logic
                        // For simplicity, we'll just show the user add form directly
                    }}>
                        Add User
                    </Button>
                </DialogTitle>
                <DialogContent dividers>
                    <Box sx={{ mb: 4, p: 2, bgcolor: 'grey.50', borderRadius: 2 }}>
                        <Typography variant="subtitle2" sx={{ mb: 2, fontWeight: 'bold' }}>Add New User Account</Typography>
                        <form onSubmit={handleUserSubmit}>
                            <Grid container spacing={2} alignItems="center">
                                <Grid item xs={3}>
                                    <TextField
                                        size="small"
                                        fullWidth
                                        label="Name"
                                        required
                                        value={userForm.name}
                                        onChange={(e) => setUserForm({ ...userForm, name: e.target.value })}
                                    />
                                </Grid>
                                <Grid item xs={4}>
                                    <TextField
                                        size="small"
                                        fullWidth
                                        label="Email"
                                        required
                                        type="email"
                                        value={userForm.email}
                                        onChange={(e) => setUserForm({ ...userForm, email: e.target.value })}
                                    />
                                </Grid>
                                <Grid item xs={3}>
                                    <TextField
                                        size="small"
                                        fullWidth
                                        label="Password"
                                        required
                                        type="password"
                                        value={userForm.password}
                                        onChange={(e) => setUserForm({ ...userForm, password: e.target.value })}
                                    />
                                </Grid>
                                <Grid item xs={2}>
                                    <Button type="submit" variant="contained" fullWidth>Add</Button>
                                </Grid>
                            </Grid>
                        </form>
                    </Box>

                    <Typography variant="subtitle2" sx={{ mb: 2, fontWeight: 'bold' }}>Existing Users</Typography>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell>Name</TableCell>
                                <TableCell>Email</TableCell>
                                <TableCell>Created</TableCell>
                                <TableCell align="right">Actions</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {usersLoading ? (
                                <TableRow><TableCell colSpan={4} align="center"><CircularProgress size={20} /></TableCell></TableRow>
                            ) : tenantUsers.length === 0 ? (
                                <TableRow><TableCell colSpan={4} align="center">No users found for this tenant.</TableCell></TableRow>
                            ) : tenantUsers.map(user => (
                                <TableRow key={user.id}>
                                    <TableCell>{user.name}</TableCell>
                                    <TableCell>{user.email}</TableCell>
                                    <TableCell>{new Date(user.created_at).toLocaleDateString()}</TableCell>
                                    <TableCell align="right">
                                        <IconButton size="small" color="error" onClick={() => handleDeleteUser(user.id)}>
                                            <DeleteIcon fontSize="small" />
                                        </IconButton>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </DialogContent>
                <DialogActions sx={{ p: 2 }}>
                    <Button onClick={() => setUserDialogOpen(false)}>Close</Button>
                </DialogActions>
            </Dialog>
        </Container>
    );
};

export default TenantUserManagementPage;
