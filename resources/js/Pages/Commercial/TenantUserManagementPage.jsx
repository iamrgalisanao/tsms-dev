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
    Tooltip,
    InputAdornment,
    MenuItem
} from '@mui/material';
import {
    Add as AddIcon,
    Edit as EditIcon,
    Delete as DeleteIcon,
    Group as GroupIcon,
    Business as BusinessIcon,
    PersonAdd as PersonAddIcon,
    Refresh as RefreshIcon,
    Badge as BadgeIcon,
    Category as CategoryIcon,
    LocationOn as LocationOnIcon,
    MapsHomeWork as MapsHomeWorkIcon,
    Info as InfoIcon
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
            <Dialog
                open={tenantDialogOpen}
                onClose={() => setTenantDialogOpen(false)}
                maxWidth="sm"
                fullWidth
                PaperProps={{
                    sx: { borderRadius: 4, boxShadow: '0 24px 48px rgba(0,0,0,0.1)' }
                }}
            >
                <form onSubmit={handleTenantSubmit}>
                    <DialogTitle sx={{ pb: 1 }}>
                        <Stack direction="row" spacing={2} alignItems="center">
                            <Box sx={{
                                bgcolor: 'primary.main',
                                color: 'white',
                                p: 1,
                                borderRadius: 2,
                                display: 'flex'
                            }}>
                                <BusinessIcon />
                            </Box>
                            <Box>
                                <Typography variant="h6" fontWeight="bold">
                                    {selectedTenant ? 'Edit Tenant Profile' : 'Register New Tenant'}
                                </Typography>
                                <Typography variant="caption" color="text.secondary">
                                    {selectedTenant ? 'Modify business details for this entity.' : 'Set up a new business entity in the commercial portal.'}
                                </Typography>
                            </Box>
                        </Stack>
                    </DialogTitle>
                    <DialogContent dividers>
                        <Box sx={{ py: 1 }}>
                            <Typography variant="overline" fontWeight="bold" color="text.secondary" gutterBottom>
                                Tenant Identity
                            </Typography>
                            <Grid container spacing={2.5} sx={{ mb: 3 }}>
                                <Grid item xs={12}>
                                    <TextField
                                        fullWidth
                                        label="Trade Name"
                                        required
                                        placeholder="e.g., Starbucks Coffee"
                                        value={tenantForm.trade_name}
                                        onChange={(e) => setTenantForm({ ...tenantForm, trade_name: e.target.value })}
                                        InputProps={{
                                            startAdornment: <InputAdornment position="start"><MapsHomeWorkIcon color="action" fontSize="small" /></InputAdornment>,
                                        }}
                                    />
                                </Grid>
                                <Grid item xs={6}>
                                    <TextField
                                        fullWidth
                                        label="Customer Code"
                                        required
                                        placeholder="T-0001"
                                        value={tenantForm.customer_code}
                                        onChange={(e) => setTenantForm({ ...tenantForm, customer_code: e.target.value })}
                                        InputProps={{
                                            startAdornment: <InputAdornment position="start"><BadgeIcon color="action" fontSize="small" /></InputAdornment>,
                                        }}
                                    />
                                </Grid>
                                <Grid item xs={6}>
                                    <TextField
                                        fullWidth
                                        select
                                        label="Status"
                                        value={tenantForm.status}
                                        onChange={(e) => setTenantForm({ ...tenantForm, status: e.target.value })}
                                    >
                                        <MenuItem value="Operational">
                                            <Stack direction="row" spacing={1} alignItems="center">
                                                <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'success.main' }} />
                                                <Typography variant="body2">Operational</Typography>
                                            </Stack>
                                        </MenuItem>
                                        <MenuItem value="Closed">
                                            <Stack direction="row" spacing={1} alignItems="center">
                                                <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'error.main' }} />
                                                <Typography variant="body2">Closed</Typography>
                                            </Stack>
                                        </MenuItem>
                                        <MenuItem value="Pending">
                                            <Stack direction="row" spacing={1} alignItems="center">
                                                <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'warning.main' }} />
                                                <Typography variant="body2">Pending</Typography>
                                            </Stack>
                                        </MenuItem>
                                    </TextField>
                                </Grid>
                            </Grid>

                            <Divider sx={{ mb: 3 }} />

                            <Typography variant="overline" fontWeight="bold" color="text.secondary" gutterBottom>
                                Settings & Location
                            </Typography>
                            <Grid container spacing={2.5}>
                                <Grid item xs={12}>
                                    <TextField
                                        fullWidth
                                        label="Category"
                                        placeholder="e.g., Food & Beverage"
                                        value={tenantForm.category}
                                        onChange={(e) => setTenantForm({ ...tenantForm, category: e.target.value })}
                                        InputProps={{
                                            startAdornment: <InputAdornment position="start"><CategoryIcon color="action" fontSize="small" /></InputAdornment>,
                                        }}
                                    />
                                </Grid>
                                <Grid item xs={6}>
                                    <TextField
                                        fullWidth
                                        label="Level"
                                        placeholder="Level-2"
                                        value={tenantForm.location_type}
                                        onChange={(e) => setTenantForm({ ...tenantForm, location_type: e.target.value })}
                                        InputProps={{
                                            startAdornment: <InputAdornment position="start"><LocationOnIcon color="action" fontSize="small" /></InputAdornment>,
                                        }}
                                    />
                                </Grid>
                                <Grid item xs={6}>
                                    <TextField
                                        fullWidth
                                        label="Unit No"
                                        placeholder="U-201"
                                        value={tenantForm.unit_no}
                                        onChange={(e) => setTenantForm({ ...tenantForm, unit_no: e.target.value })}
                                        InputProps={{
                                            startAdornment: <InputAdornment position="start"><InfoIcon color="action" fontSize="small" /></InputAdornment>,
                                        }}
                                    />
                                </Grid>
                            </Grid>
                        </Box>
                    </DialogContent>
                    <DialogActions sx={{ p: 3, bgcolor: 'grey.50' }}>
                        <Button
                            onClick={() => setTenantDialogOpen(false)}
                            sx={{ color: 'text.secondary', fontWeight: 'bold' }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="contained"
                            size="large"
                            sx={{
                                fontWeight: 'bold',
                                px: 4,
                                borderRadius: 2,
                                boxShadow: '0 8px 16px rgba(29, 67, 155, 0.2)'
                            }}
                        >
                            {selectedTenant ? 'Update Entity' : 'Create Entity'}
                        </Button>
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
