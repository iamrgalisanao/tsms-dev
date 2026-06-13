import React, { useState, useEffect, useCallback } from 'react';
import {
    Box,
    Typography,
    Stack,
    Snackbar,
    Alert,
    Breadcrumbs,
    Link as MuiLink,
    Button,
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import PeopleIcon from '@mui/icons-material/People';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import UserFilterBar from '../Components/users/UserFilterBar';
import UserTable from '../Components/users/UserTable';
import UserFormDialog from '../Components/users/UserFormDialog';
import userService from '../services/userService';

const UserManagementPage = () => {
    useEffect(() => {
        document.title = "User Directory & IDM | TSMS";
    }, []);

    const [users, setUsers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        search: '',
        role: ''
    });

    // Pagination state
    const [page, setPage] = useState(0);
    const [rowsPerPage, setRowsPerPage] = useState(10);
    const [totalCount, setTotalCount] = useState(0);

    // Dialog & Notification state
    const [dialogOpen, setDialogOpen] = useState(false);
    const [selectedUser, setSelectedUser] = useState(null);
    const [notification, setNotification] = useState({ open: false, message: '', severity: 'success' });

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const [usersData, rolesData] = await Promise.all([
                userService.getUsers(filters, page + 1, rowsPerPage),
                userService.getRoles()
            ]);
            setUsers(usersData.data);
            setTotalCount(usersData.total);
            setRoles(rolesData);
        } catch (error) {
            console.error('Error fetching user data:', error);
            setNotification({
                open: true,
                message: 'Security breach or network failure during data synchronization.',
                severity: 'error'
            });
        } finally {
            setLoading(false);
        }
    }, [filters, page, rowsPerPage]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleFilterChange = (newFilters) => {
        setFilters(newFilters);
        setPage(0);
    };

    const handleReset = () => {
        setFilters({
            search: '',
            role: ''
        });
        setPage(0);
    };

    const handlePageChange = (event, newPage) => {
        setPage(newPage);
    };

    const handleRowsPerPageChange = (event) => {
        setRowsPerPage(parseInt(event.target.value, 10));
        setPage(0);
    };

    const handleAddUser = () => {
        setSelectedUser(null);
        setDialogOpen(true);
    };

    const handleEditUser = (user) => {
        setSelectedUser(user);
        setDialogOpen(true);
    };

    const handleDeleteUser = async (user) => {
        if (!confirm(`CRITICAL ACTION: This will PERMANENTLY REMOVE access for ${user.name}. Proceed?`)) {
            return;
        }

        try {
            const response = await userService.deleteUser(user.id);
            if (response.success) {
                setNotification({
                    open: true,
                    message: "Identity revoked and deleted successfully.",
                    severity: 'warning'
                });
                fetchData();
            }
        } catch (error) {
            setNotification({
                open: true,
                message: error.response?.data?.message || 'Access revocation sequence failed.',
                severity: 'error'
            });
        }
    };

    const handleSaveUser = async (formData) => {
        try {
            let response;
            if (selectedUser) {
                response = await userService.updateUser(selectedUser.id, formData);
            } else {
                response = await userService.createUser(formData);
            }

            if (response.success) {
                setNotification({
                    open: true,
                    message: response.message,
                    severity: 'success'
                });
                setDialogOpen(false);
                fetchData();
            }
        } catch (error) {
            setNotification({
                open: true,
                message: error.response?.data?.message || 'Identity storage sequence failed.',
                severity: 'error'
            });
        }
    };

    return (
        <Box sx={{ pb: 8 }}>
            <Box sx={{ py: 3 }}>
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>USER DIRECTORY</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} sx={{ mb: 5 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <PeopleIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    User Management
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Orchestrate system access, roles, and administrative permissions.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction="row" spacing={1.5}>
                        <Button
                            variant="outlined"
                            color="inherit"
                            sx={{ borderRadius: 3, px: 2.5, py: 1.2, fontWeight: 700, borderColor: 'divider', textTransform: 'none', bgcolor: 'white' }}
                        >
                            Export Directory
                        </Button>
                        <Button
                            variant="contained"
                            startIcon={<PersonAddIcon />}
                            onClick={handleAddUser}
                            sx={{ borderRadius: 3, px: 3, py: 1.2, fontWeight: 800, textTransform: 'none', boxShadow: '0 4px 15px rgba(25, 118, 210, 0.3)' }}
                        >
                            Provision User
                        </Button>
                    </Stack>
                </Stack>

                <UserFilterBar
                    filters={filters}
                    onFilterChange={handleFilterChange}
                    onReset={handleReset}
                    roles={roles}
                />

                <UserTable
                    users={users}
                    loading={loading}
                    page={page}
                    rowsPerPage={rowsPerPage}
                    totalCount={totalCount}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    onEdit={handleEditUser}
                    onDelete={handleDeleteUser}
                />
            </Box>

            <UserFormDialog
                open={dialogOpen}
                user={selectedUser}
                onClose={() => setDialogOpen(false)}
                onSave={handleSaveUser}
                roles={roles}
            />

            <Snackbar
                open={notification.open}
                autoHideDuration={5000}
                onClose={() => setNotification({ ...notification, open: false })}
                anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            >
                <Alert
                    severity={notification.severity}
                    variant="filled"
                    sx={{
                        borderRadius: 3,
                        fontWeight: 700,
                        boxShadow: '0 10px 30px rgba(0,0,0,0.15)',
                        minWidth: 300
                    }}
                >
                    {notification.message}
                </Alert>
            </Snackbar>
        </Box>
    );
};

export default UserManagementPage;
