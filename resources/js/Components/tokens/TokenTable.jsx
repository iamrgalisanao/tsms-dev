import React from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    Chip,
    Typography,
    Box,
    Button,
    Stack,
    CircularProgress,
    Tooltip,
    TablePagination
} from '@mui/material';
import { format } from 'date-fns';
import KeyIcon from '@mui/icons-material/Key';
import BlockIcon from '@mui/icons-material/Block';
import RefreshIcon from '@mui/icons-material/Refresh';

const TokenTable = ({
    terminals,
    loading,
    page,
    rowsPerPage,
    totalCount,
    onPageChange,
    onRowsPerPageChange,
    onRegenerate,
    onRevoke
}) => {

    const headerStyles = {
        fontWeight: 800,
        fontSize: '0.65rem',
        textTransform: 'uppercase',
        letterSpacing: '0.1em',
        color: '#EB342E',
        py: 2.5,
        bgcolor: 'white',
        borderBottom: '2px solid',
        borderColor: 'divider'
    };

    const cellStyles = {
        fontSize: '0.85rem',
        color: 'text.primary',
        py: 2.5,
        borderBottom: '1px solid rgba(0,0,0,0.05)'
    };

    const getStatusChip = (terminal) => {
        const isActive = terminal.status_id === 1 && terminal.is_active;

        if (isActive) {
            return (
                <Chip
                    label="ACTIVE"
                    size="small"
                    sx={{
                        bgcolor: 'success.main',
                        color: 'white',
                        fontWeight: 800,
                        fontSize: '0.6rem',
                        borderRadius: 1.5,
                        height: 22,
                        boxShadow: '0 2px 6px rgba(46, 125, 50, 0.2)'
                    }}
                />
            );
        }

        if (terminal.status_id === 3) {
            return (
                <Chip
                    label="REVOKED"
                    size="small"
                    sx={{
                        bgcolor: 'error.main',
                        color: 'white',
                        fontWeight: 800,
                        fontSize: '0.6rem',
                        borderRadius: 1.5,
                        height: 22
                    }}
                />
            );
        }

        return (
            <Chip
                label="INACTIVE"
                size="small"
                sx={{
                    bgcolor: 'grey.300',
                    color: 'grey.700',
                    fontWeight: 800,
                    fontSize: '0.6rem',
                    borderRadius: 1.5,
                    height: 22
                }}
            />
        );
    };

    const formatApiKey = (terminal) => {
        if (!terminal.tokens || terminal.tokens.length === 0) {
            return (
                <Typography variant="caption" sx={{ color: 'text.disabled', fontStyle: 'italic' }}>
                    UNPROVISIONED
                </Typography>
            );
        }
        const token = terminal.tokens[0];
        const dateStr = format(new Date(token.created_at), 'yyyy-MM-dd HH:mm');
        return (
            <Box sx={{
                bgcolor: 'grey.50',
                px: 1,
                py: 0.5,
                borderRadius: 1,
                display: 'inline-flex',
                alignItems: 'center',
                border: '1px solid',
                borderColor: 'divider',
                color: 'text.secondary',
                fontSize: '0.75rem',
                fontFamily: 'monospace'
            }}>
                <Typography variant="caption" sx={{ fontWeight: 800, color: 'primary.main', mr: 1, borderRight: '1px solid', borderColor: 'divider', pr: 1 }}>
                    TER
                </Typography>
                {dateStr}
            </Box>
        );
    };

    return (
        <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 4, overflow: 'hidden', boxShadow: '0 4px 20px rgba(0,0,0,0.02)' }}>
            <TableContainer>
                <Table stickyHeader size="small">
                    <TableHead>
                        <TableRow>
                            <TableCell sx={headerStyles}>Tenant / Entity</TableCell>
                            <TableCell align="center" sx={headerStyles}>Machine</TableCell>
                            <TableCell sx={headerStyles}>Hardware ID (SN)</TableCell>
                            <TableCell sx={headerStyles}>Provisioned</TableCell>
                            <TableCell sx={headerStyles}>Expiry</TableCell>
                            <TableCell align="center" sx={headerStyles}>Security State</TableCell>
                            <TableCell sx={headerStyles}>Active Identity</TableCell>
                            <TableCell align="right" sx={headerStyles}>Orchestration</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {loading ? (
                            Array.from(new Array(5)).map((_, index) => (
                                <TableRow key={index}>
                                    <TableCell colSpan={8} align="center" sx={{ py: 6 }}>
                                        <CircularProgress size={24} sx={{ color: 'primary.main' }} />
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : terminals.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={8} align="center" sx={{ py: 10 }}>
                                    <Box sx={{ opacity: 0.4 }}>
                                        <KeyIcon sx={{ fontSize: 48, mb: 1 }} />
                                        <Typography variant="body2" sx={{ fontWeight: 700 }}>No terminal nodes indexed.</Typography>
                                    </Box>
                                </TableCell>
                            </TableRow>
                        ) : (
                            terminals.map((terminal) => (
                                <TableRow key={terminal.id} hover sx={{ '&:hover': { bgcolor: 'rgba(0,0,0,0.01)' } }}>
                                    <TableCell sx={cellStyles}>
                                        <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>
                                            {terminal.tenant?.trade_name || 'System Primary'}
                                        </Typography>
                                        <Typography variant="caption" sx={{ color: 'primary.main', fontWeight: 600, opacity: 0.8 }}>
                                            ARC-{terminal.tenant_id || '000'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell align="center" sx={cellStyles}>
                                        <Typography variant="caption" sx={{ fontWeight: 800, bgcolor: 'grey.100', px: 1, py: 0.2, borderRadius: 1 }}>
                                            #{terminal.machine_number || terminal.id}
                                        </Typography>
                                    </TableCell>
                                    <TableCell sx={cellStyles}>
                                        <Typography variant="body2" sx={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                            {terminal.serial_number}
                                        </Typography>
                                    </TableCell>
                                    <TableCell sx={cellStyles}>
                                        <Typography variant="body2" sx={{ fontWeight: 500 }}>
                                            {terminal.created_at ? format(new Date(terminal.created_at), 'MMM dd, yyyy') : '-'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell sx={cellStyles}>
                                        <Typography variant="body2" sx={{ fontWeight: 700, color: terminal.expires_at ? 'text.primary' : 'success.main' }}>
                                            {terminal.expires_at ? format(new Date(terminal.expires_at), 'MMM dd, yyyy') : 'PERMANENT'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell align="center" sx={cellStyles}>{getStatusChip(terminal)}</TableCell>
                                    <TableCell sx={cellStyles}>{formatApiKey(terminal)}</TableCell>
                                    <TableCell align="right" sx={cellStyles}>
                                        <Stack direction="row" spacing={1} justifyContent="flex-end">
                                            <Tooltip title="Regenerate Identity">
                                                <Button
                                                    size="small"
                                                    onClick={() => onRegenerate(terminal)}
                                                    sx={{
                                                        minWidth: 36,
                                                        height: 36,
                                                        borderRadius: 2,
                                                        bgcolor: 'primary.50',
                                                        color: 'primary.main',
                                                        '&:hover': { bgcolor: 'primary.100' }
                                                    }}
                                                >
                                                    <RefreshIcon fontSize="small" />
                                                </Button>
                                            </Tooltip>
                                            <Tooltip title="Revoke Authorization">
                                                <Button
                                                    size="small"
                                                    onClick={() => onRevoke(terminal)}
                                                    sx={{
                                                        minWidth: 36,
                                                        height: 36,
                                                        borderRadius: 2,
                                                        bgcolor: 'error.50',
                                                        color: 'error.main',
                                                        '&:hover': { bgcolor: 'error.100' }
                                                    }}
                                                >
                                                    <BlockIcon fontSize="small" />
                                                </Button>
                                            </Tooltip>
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </TableContainer>
            <Box sx={{ borderTop: '1px solid', borderColor: 'divider', bgcolor: 'grey.50' }}>
                <TablePagination
                    rowsPerPageOptions={[10, 25, 50, 100]}
                    component="div"
                    count={totalCount}
                    rowsPerPage={rowsPerPage}
                    page={page}
                    onPageChange={onPageChange}
                    onRowsPerPageChange={onRowsPerPageChange}
                    sx={{
                        '& .MuiTablePagination-selectLabel, & .MuiTablePagination-displayedRows': {
                            fontWeight: 700,
                            fontSize: '0.75rem',
                            color: 'text.secondary'
                        }
                    }}
                />
            </Box>
        </Paper>
    );
};

export default TokenTable;
