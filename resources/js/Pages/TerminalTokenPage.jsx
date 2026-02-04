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
    Divider
} from '@mui/material';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';
import HomeIcon from '@mui/icons-material/Home';
import KeyIcon from '@mui/icons-material/Key';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import AddModeratorIcon from '@mui/icons-material/AddModerator';
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

    const handleRegenerate = async (terminal) => {
        if (!confirm(`SECURITY OVERRIDE: Regenerating keys for ${terminal.serial_number} will revoke current sessions. Continue?`)) {
            return;
        }

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

    const handleRevoke = async (terminal) => {
        if (!confirm(`CRITICAL ACTION: This will PERMANENTLY BAN all active tokens for ${terminal.serial_number}. Confirm?`)) {
            return;
        }

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
        </Box>
    );
};

export default TerminalTokenPage;
