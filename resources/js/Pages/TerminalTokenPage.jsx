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
    Divider,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    DialogContentText,
    TextField,
    MenuItem,
    IconButton
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import KeyIcon from '@mui/icons-material/Key';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import AddModeratorIcon from '@mui/icons-material/AddModerator';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import TokenFilterBar from '../Components/tokens/TokenFilterBar';
import TokenTable from '../Components/tokens/TokenTable';
import NewTokenDialog from '../Components/tokens/NewTokenDialog';
import { terminalTokenService } from '../services/terminalTokenService';

const TerminalTokenPage = () => {
    useEffect(() => {
        document.title = "Terminal Identity Management | TSMS";
    }, []);

    const [terminals, setTerminals] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({
        status: '',
        search: '',
        per_page: 10
    });

    // Pagination state
    const [page, setPage] = useState(0);
    const [rowsPerPage, setRowsPerPage] = useState(10);
    const [totalCount, setTotalCount] = useState(0);

    // Dialog & Notification state
    const [newToken, setNewToken] = useState(null);
    const [selectedTerminal, setSelectedTerminal] = useState(null);
    const [notification, setNotification] = useState({ open: false, message: '', severity: 'success' });

    // Register terminal dialog state
    const [registerDialogOpen, setRegisterDialogOpen] = useState(false);
    const [registerForm, setRegisterForm] = useState({
        tenant_id: '',
        serial_number: '',
        machine_number: '',
        ip_address: ''
    });
    const [registerSubmitting, setRegisterSubmitting] = useState(false);
    const [tenants, setTenants] = useState([]);

    // Custom Confirmation Dialog State
    const [confirmDialog, setConfirmDialog] = useState({
        open: false,
        title: '',
        message: '',
        actionType: null, // 'regenerate' or 'revoke'
        targetTerminal: null
    });

    // Load tenants for registration dropdown
    useEffect(() => {
        const loadTenants = async () => {
            try {
                const data = await terminalTokenService.getTenants();
                setTenants(data || []);
            } catch (error) {
                console.error('Error loading tenants for terminal registration:', error);
                setNotification({
                    open: true,
                    message: 'Failed to load tenants for registration form.',
                    severity: 'error'
                });
            }
        };

        loadTenants();
    }, []);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const response = await terminalTokenService.getTerminalsWithTokens(
                filters,
                page + 1,
                rowsPerPage
            );
            setTerminals(response.data);
            setTotalCount(response.meta.total);
        } catch (error) {
            console.error('Error fetching terminal tokens:', error);
            setNotification({
                open: true,
                message: 'Encrypted data stream failed to synchronize.',
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
        if (newFilters.per_page) {
            setRowsPerPage(newFilters.per_page);
        }
        setPage(0);
    };

    const handleReset = (resetFilters) => {
        setFilters(resetFilters);
        setRowsPerPage(resetFilters.per_page);
        setPage(0);
    };

    const handlePageChange = (event, newPage) => {
        setPage(newPage);
    };

    const handleRowsPerPageChange = (event) => {
        const newRowsPerPage = parseInt(event.target.value, 10);
        setRowsPerPage(newRowsPerPage);
        setFilters(prev => ({ ...prev, per_page: newRowsPerPage }));
        setPage(0);
    };

    const handleRegenerate = (terminal) => {
        setConfirmDialog({
            open: true,
            title: 'SECURITY OVERRIDE',
            message: `Regenerating keys for ${terminal.serial_number} will revoke its current sessions. Do you wish to continue?`,
            actionType: 'regenerate',
            targetTerminal: terminal
        });
    };

    const executeRegenerate = async (terminal) => {
        try {
            const response = await terminalTokenService.regenerateToken(terminal.id);
            if (response.success) {
                setNewToken(response.data.access_token);
                setSelectedTerminal(terminal);
                fetchData();
                setNotification({
                    open: true,
                    message: `New identity provisioned for node: ${terminal.serial_number}`,
                    severity: 'success'
                });
            }
        } catch (error) {
            setNotification({
                open: true,
                message: error.response?.data?.message || 'Provisioning sequence failed.',
                severity: 'error'
            });
        }
    };

    const handleRevoke = (terminal) => {
        setConfirmDialog({
            open: true,
            title: 'CRITICAL ACTION',
            message: `This will PERMANENTLY BAN all active tokens for ${terminal.serial_number}. Are you sure you want to proceed?`,
            actionType: 'revoke',
            targetTerminal: terminal
        });
    };

    const executeRevoke = async (terminal) => {
        try {
            const response = await terminalTokenService.revokeTokens(terminal.id);
            if (response.success) {
                setNotification({
                    open: true,
                    message: "Identity invalidated successfully.",
                    severity: 'warning'
                });
                fetchData();
            }
        } catch (error) {
            setNotification({
                open: true,
                message: error.response?.data?.message || 'Invalidation sequence failed.',
                severity: 'error'
            });
        }
    };

    const handleConfirmClose = () => {
        setConfirmDialog({ ...confirmDialog, open: false });
    };

    const handleConfirmExecute = async () => {
        const { actionType, targetTerminal } = confirmDialog;
        handleConfirmClose(); // Close the dialog immediately

        if (actionType === 'regenerate') {
            await executeRegenerate(targetTerminal);
        } else if (actionType === 'revoke') {
            await executeRevoke(targetTerminal);
        }
    };

    const handleOpenRegister = () => {
        setRegisterDialogOpen(true);
    };

    const handleCloseRegister = () => {
        if (registerSubmitting) return;
        setRegisterDialogOpen(false);
    };

    const handleRegisterChange = (field, value) => {
        setRegisterForm((prev) => ({ ...prev, [field]: value }));
    };

    const handleRegisterSubmit = async (event) => {
        event.preventDefault();

        if (!registerForm.tenant_id || !registerForm.serial_number) {
            setNotification({
                open: true,
                message: 'Tenant and Serial Number are required.',
                severity: 'warning'
            });
            return;
        }

        try {
            setRegisterSubmitting(true);
            const response = await terminalTokenService.registerTerminal(registerForm);

            if (response.success) {
                const terminal = response.data?.terminal;
                const token = response.data?.access_token;

                if (token) {
                    setNewToken(token);
                    setSelectedTerminal(terminal);
                }

                setNotification({
                    open: true,
                    message: `Terminal ${terminal?.serial_number || registerForm.serial_number} registered successfully.`,
                    severity: 'success'
                });

                setRegisterForm({ tenant_id: '', serial_number: '', machine_number: '', ip_address: '' });
                setRegisterDialogOpen(false);
                fetchData();
            } else {
                setNotification({
                    open: true,
                    message: response.message || 'Failed to register terminal.',
                    severity: 'error'
                });
            }
        } catch (error) {
            console.error('Terminal registration error context:', {
                status: error.response?.status,
                data: error.response?.data,
                headers: error.response?.headers
            });

            let message = error.response?.data?.message || 'Registration sequence failed.';

            if (error.response?.data?.errors) {
                const errors = error.response.data.errors;
                const firstField = Object.keys(errors)[0];
                if (firstField && errors[firstField][0]) {
                    message = `${errors[firstField][0]}`;
                }
            }

            setNotification({
                open: true,
                message,
                severity: 'error'
            });
        } finally {
            setRegisterSubmitting(false);
        }
    };

    return (
        <Box sx={{ pb: 8 }}>
            <Box sx={{ py: 3 }}>
                {/* Unified Breadcrumbs */}
                <Breadcrumbs
                    separator={<NavigateNextIcon fontSize="small" />}
                    sx={{ mb: 4, '& .MuiTypography-root': { fontWeight: 700, fontSize: '0.75rem', letterSpacing: '0.05em' } }}
                >
                    <MuiLink underline="hover" color="inherit" href="/dashboard" sx={{ display: 'flex', alignItems: 'center', opacity: 0.6 }}>
                        <HomeIcon sx={{ mr: 0.5, fontSize: 16 }} />
                        SYSTEM
                    </MuiLink>
                    <Typography color="primary.main" sx={{ fontWeight: 800 }}>IDENTITY ARCHIVE</Typography>
                </Breadcrumbs>

                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} sx={{ mb: 5 }} spacing={4}>
                    <Box>
                        <Stack direction="row" spacing={2.5} alignItems="center" sx={{ mb: 1.5 }}>
                            <Box sx={{ p: 1.5, bgcolor: 'primary.main', color: 'white', borderRadius: 3, display: 'flex', boxShadow: '0 8px 25px rgba(25, 118, 210, 0.25)' }}>
                                <KeyIcon sx={{ fontSize: 32 }} />
                            </Box>
                            <div>
                                <Typography variant="h2" sx={{ fontWeight: 950, color: 'text.primary', letterSpacing: '-0.03em', mb: 0.5 }}>
                                    Terminal Identity Management
                                </Typography>
                                <Typography variant="body1" sx={{ color: 'text.secondary', fontWeight: 500, opacity: 0.8 }}>
                                    Authorize and orchestrate cryptographic node identities across the global grid.
                                </Typography>
                            </div>
                        </Stack>
                    </Box>

                    <Stack direction="row" spacing={1.5} alignItems="center">
                        <Box
                            sx={{
                                p: 1.5,
                                px: 2,
                                bgcolor: 'white',
                                borderRadius: 3,
                                border: '1px solid',
                                borderColor: 'divider',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 2,
                                mr: 1,
                                boxShadow: '0 4px 12px rgba(0,0,0,0.02)'
                            }}
                        >
                            <Box>
                                <Typography variant="h4" sx={{ fontWeight: 950, lineHeight: 1, color: 'primary.main' }}>
                                    {totalCount}
                                </Typography>
                                <Typography variant="caption" sx={{ fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.05em' }}>
                                    NODES
                                </Typography>
                            </Box>
                        </Box>

                        <Button
                            variant="contained"
                            color="primary"
                            startIcon={<AddModeratorIcon />}
                            onClick={handleOpenRegister}
                            sx={{
                                fontWeight: 800,
                                borderRadius: 2.5,
                                px: 3,
                                textTransform: 'none',
                                boxShadow: '0 8px 16px rgba(25, 118, 210, 0.2)'
                            }}
                        >
                            Register Terminal
                        </Button>
                    </Stack>
                </Stack>

                <TokenFilterBar
                    filters={filters}
                    onFilterChange={handleFilterChange}
                    onReset={handleReset}
                />

                <TokenTable
                    terminals={terminals}
                    loading={loading}
                    page={page}
                    rowsPerPage={rowsPerPage}
                    totalCount={totalCount}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    onRegenerate={handleRegenerate}
                    onRevoke={handleRevoke}
                />
            </Box>

            <NewTokenDialog
                open={!!newToken}
                token={newToken}
                onClose={() => setNewToken(null)}
                terminalName={selectedTerminal ? `${selectedTerminal.serial_number} (${selectedTerminal.tenant?.trade_name})` : ''}
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

            {/* Custom Confirmation Dialog replacing window.confirm */}
            <Dialog
                open={confirmDialog.open}
                onClose={handleConfirmClose}
                maxWidth="xs"
                fullWidth
            >
                <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1, fontWeight: 900, color: confirmDialog.actionType === 'revoke' ? 'error.main' : 'warning.main' }}>
                    <WarningAmberIcon />
                    {confirmDialog.title}
                </DialogTitle>
                <DialogContent>
                    <DialogContentText sx={{ fontWeight: 500, color: 'text.primary' }}>
                        {confirmDialog.message}
                    </DialogContentText>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 3 }}>
                    <Button onClick={handleConfirmClose} color="inherit" sx={{ fontWeight: 600 }}>
                        Cancel
                    </Button>
                    <Button
                        onClick={handleConfirmExecute}
                        variant="contained"
                        color={confirmDialog.actionType === 'revoke' ? 'error' : 'primary'}
                        sx={{ fontWeight: 700 }}
                    >
                        Proceed
                    </Button>
                </DialogActions>
            </Dialog>

            {/* Register POS Terminal Dialog */}
            <Dialog
                open={registerDialogOpen}
                onClose={handleCloseRegister}
                maxWidth="sm"
                fullWidth
            >
                <DialogTitle sx={{ fontWeight: 900 }}>Register POS Terminal</DialogTitle>
                <DialogContent dividers>
                    <Box component="form" onSubmit={handleRegisterSubmit} sx={{ mt: 1 }}>
                        <Stack spacing={2.5}>
                            <TextField
                                select
                                label="Tenant"
                                value={registerForm.tenant_id}
                                onChange={(e) => handleRegisterChange('tenant_id', e.target.value)}
                                fullWidth
                                required
                                size="small"
                            >
                                {tenants.map((tenant) => (
                                    <MenuItem key={tenant.id} value={tenant.id}>
                                        {tenant.trade_name}
                                    </MenuItem>
                                ))}
                            </TextField>

                            <TextField
                                label="Serial Number"
                                value={registerForm.serial_number}
                                onChange={(e) => handleRegisterChange('serial_number', e.target.value)}
                                fullWidth
                                required
                                size="small"
                                inputProps={{ maxLength: 255 }}
                            />

                            <TextField
                                label="Machine Number"
                                value={registerForm.machine_number}
                                onChange={(e) => handleRegisterChange('machine_number', e.target.value)}
                                fullWidth
                                size="small"
                                inputProps={{ maxLength: 255 }}
                            />

                            <TextField
                                label="IP Address (optional)"
                                value={registerForm.ip_address}
                                onChange={(e) => handleRegisterChange('ip_address', e.target.value)}
                                fullWidth
                                size="small"
                                inputProps={{ maxLength: 255 }}
                            />
                        </Stack>
                    </Box>
                </DialogContent>
                <DialogActions sx={{ px: 3, py: 2.5 }}>
                    <Button onClick={handleCloseRegister} color="inherit" sx={{ fontWeight: 600 }} disabled={registerSubmitting}>
                        Cancel
                    </Button>
                    <Button
                        onClick={handleRegisterSubmit}
                        variant="contained"
                        color="primary"
                        sx={{ fontWeight: 700 }}
                        disabled={registerSubmitting}
                    >
                        {registerSubmitting ? 'Registering…' : 'Register Terminal'}
                    </Button>
                </DialogActions>
            </Dialog>
        </Box>
    );
};

export default TerminalTokenPage;
