import React, { useState, useEffect } from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Paper,
    Typography,
    Box,
    IconButton,
    Stack,
    CircularProgress,
    Pagination,
    Button,
    InputBase,
    Avatar,
    Select,
    MenuItem,
    FormControl,
    InputLabel,
    Divider,
    TextField,
    InputAdornment,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions
} from '@mui/material';
import { formatDistanceToNow } from 'date-fns';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import SearchIcon from '@mui/icons-material/Search';
import FilterListIcon from '@mui/icons-material/FilterList';
import SyncIcon from '@mui/icons-material/Sync';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const SubmissionEventsTable = ({ data, loading, filters, terminals, onPageChange, onFilterChange }) => {
    const [localFilters, setLocalFilters] = useState(filters || {});
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLog, setDetailLog] = useState(null);

    useEffect(() => {
        setLocalFilters(filters || {});
    }, [filters]);

    const handleFilterChange = (field, value) => {
        setLocalFilters(prev => ({ ...prev, [field]: value }));
    };

    const handleShowDetails = (log) => {
        setDetailLog(log);
        setDetailOpen(true);
    };

    const handleCloseDetails = () => {
        setDetailOpen(false);
    };

    const handleApplyFilters = () => {
        if (onFilterChange) onFilterChange(localFilters);
    };

    const handleResetFilters = () => {
        const reset = {
            search: '',
            terminal: '',
            status: '',
            date_from: '',
            date_to: ''
        };
        setLocalFilters(reset);
        if (onFilterChange) onFilterChange(reset);
    };

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text);
    };

    const getStatusStyles = (status) => {
        const s = (status || '').toUpperCase();
        switch (s) {
            case 'SUCCESS':
                return {
                    color: '#10B981',
                    bgcolor: 'rgba(16, 185, 129, 0.05)',
                    border: '1px solid rgba(16, 185, 129, 0.2)'
                };
            case 'PENDING':
                return {
                    color: '#F59E0B',
                    bgcolor: 'rgba(245, 158, 11, 0.05)',
                    border: '1px solid rgba(245, 158, 11, 0.2)'
                };
            case 'FAILED':
                return {
                    color: '#EB342E',
                    bgcolor: 'rgba(235, 52, 46, 0.05)',
                    border: '1px solid rgba(235, 52, 46, 0.2)'
                };
            default:
                return {
                    color: '#64748B',
                    bgcolor: 'rgba(100, 116, 139, 0.05)',
                    border: '1px solid rgba(100, 116, 139, 0.2)'
                };
        }
    };

    const getTenantColor = (name) => {
        const colors = ['#E11D48', '#10B981', '#3B82F6', '#F59E0B', '#8B5CF6'];
        const charCode = (name || 'A').charCodeAt(0);
        return colors[charCode % colors.length];
    };

    const headerCellStyle = {
        color: '#EB342E', // PITX Red
        fontWeight: 800,
        fontSize: '0.65rem',
        textTransform: 'uppercase',
        letterSpacing: '0.1em',
        borderBottom: '1px solid #F1F5F9',
        py: 2,
        bgcolor: '#FFFFFF'
    };

    const bodyCellStyle = {
        color: '#475569',
        borderBottom: '1px solid #F1F5F9',
        py: 2.5
    };

    if (loading) {
        return (
            <Box sx={{ py: 20, textAlign: 'center', bgcolor: '#FFFFFF', borderRadius: 4, border: '1px solid #E2E8F0' }}>
                <CircularProgress size={32} />
                <Typography variant="body2" sx={{ mt: 2, fontWeight: 700, color: '#64748B' }}>Loading Submissions...</Typography>
            </Box>
        );
    }

    const items = data?.data || [];
    const meta = data || {};
    const from = meta.from || 1;
    const to = meta.to || items.length;
    const total = meta.total || 0;

    return (
        <Stack spacing={3}>
            {/* Filter Section - Based on Reference Image */}
            <Paper elevation={0} sx={{ p: 4, borderRadius: 4, border: '1px solid #E2E8F0', bgcolor: '#FFFFFF' }}>
                <Stack spacing={3}>
                    {/* Top Row: Search */}
                    <Box sx={{ 
                        display: 'flex', 
                        alignItems: 'center', 
                        bgcolor: '#F8FAFC', 
                        borderRadius: 3, 
                        px: 3, 
                        py: 1.5,
                        border: '1px solid #E2E8F0',
                        '&:focus-within': { borderColor: '#1D439B', bgcolor: '#FFFFFF', boxShadow: '0 0 0 2px rgba(29, 67, 155, 0.1)' }
                    }}>
                        <SearchIcon sx={{ color: '#1D439B', mr: 2, fontSize: 24 }} />
                        <InputBase
                            fullWidth
                            placeholder="Search logs by action, context, or resource identifier..."
                            value={localFilters.search || ''}
                            onChange={(e) => handleFilterChange('search', e.target.value)}
                            onKeyPress={(e) => e.key === 'Enter' && handleApplyFilters()}
                            sx={{ color: '#1E293B', fontSize: '1rem', fontWeight: 500 }}
                        />
                    </Box>

                    {/* Middle Row: Controls */}
                    <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems="center">
                        <FormControl sx={{ flex: 1, minWidth: 150 }} size="small">
                            <InputLabel sx={{ fontWeight: 600 }}>Terminal</InputLabel>
                            <Select
                                value={localFilters.terminal || ''}
                                label="Terminal"
                                onChange={(e) => handleFilterChange('terminal', e.target.value)}
                                sx={{ borderRadius: 2.5, bgcolor: '#F8FAFC', fontWeight: 600 }}
                            >
                                <MenuItem value="">All Terminals</MenuItem>
                                {terminals?.map((terminal) => (
                                    <MenuItem key={terminal.id} value={terminal.id}>
                                        {terminal.serial_number}
                                    </MenuItem>
                                ))}
                            </Select>
                        </FormControl>

                        <FormControl sx={{ flex: 1, minWidth: 150 }} size="small">
                            <InputLabel sx={{ fontWeight: 600 }}>Status</InputLabel>
                            <Select
                                value={localFilters.status || ''}
                                label="Status"
                                onChange={(e) => handleFilterChange('status', e.target.value)}
                                sx={{ borderRadius: 2.5, bgcolor: '#F8FAFC', fontWeight: 600 }}
                            >
                                <MenuItem value="">All Statuses</MenuItem>
                                <MenuItem value="SUCCESS">Success</MenuItem>
                                <MenuItem value="PENDING">Pending</MenuItem>
                                <MenuItem value="FAILED">Failed</MenuItem>
                            </Select>
                        </FormControl>

                        <TextField
                            type="date"
                            size="small"
                            label="From"
                            value={localFilters.date_from || ''}
                            onChange={(e) => handleFilterChange('date_from', e.target.value)}
                            sx={{ flex: 0.8 }}
                            InputLabelProps={{ shrink: true }}
                        />

                        <TextField
                            type="date"
                            size="small"
                            label="To"
                            value={localFilters.date_to || ''}
                            onChange={(e) => handleFilterChange('date_to', e.target.value)}
                            sx={{ flex: 0.8 }}
                            InputLabelProps={{ shrink: true }}
                        />

                        <Stack direction="row" spacing={1.5}>
                            <Button
                                variant="contained"
                                startIcon={<SyncIcon />}
                                onClick={handleApplyFilters}
                                sx={{
                                    borderRadius: 3,
                                    textTransform: 'none',
                                    fontWeight: 800,
                                    px: 4,
                                    bgcolor: '#1D439B',
                                    boxShadow: '0 4px 12px rgba(29, 67, 155, 0.2)',
                                    '&:hover': { bgcolor: '#153170' }
                                }}
                            >
                                Sync Logs
                            </Button>
                            <IconButton 
                                onClick={handleResetFilters}
                                sx={{ 
                                    border: '1px solid #E2E8F0', 
                                    borderRadius: 2.5,
                                    color: '#64748B',
                                    '&:hover': { bgcolor: '#F8FAFC' }
                                }}
                            >
                                <RestartAltIcon />
                            </IconButton>
                        </Stack>
                    </Stack>

                    {/* Bottom Row: Info */}
                    <Stack direction="row" spacing={1} alignItems="center">
                        <InfoOutlinedIcon sx={{ fontSize: 16, color: '#64748B' }} />
                        <Typography variant="caption" sx={{ color: '#64748B', fontWeight: 500 }}>
                            Direct telemetry and payload data submitted from remote terminal hardware. You can search by Submission ID, Terminal ID, or Tenant name.
                        </Typography>
                    </Stack>

                    <Divider sx={{ borderStyle: 'dashed' }} />

                    <Typography variant="caption" sx={{ fontWeight: 800, color: '#94A3B8', textTransform: 'uppercase', letterSpacing: '0.1em' }}>
                        Viewing: Node Submissions Registry
                    </Typography>
                </Stack>
            </Paper>

            {/* Table Section */}
            <Paper elevation={0} sx={{ bgcolor: '#FFFFFF', borderRadius: 4, overflow: 'hidden', border: '1px solid #E2E8F0' }}>
                <Box sx={{ p: 4, borderBottom: '1px solid #F1F5F9' }}>
                    <Typography variant="body1" sx={{ color: '#1E293B', fontWeight: 950, letterSpacing: '0.05em', textTransform: 'uppercase' }}>
                        Recent Node Submissions
                    </Typography>
                </Box>

                <TableContainer>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell sx={headerCellStyle}>Submission ID</TableCell>
                                <TableCell sx={headerCellStyle}>Terminal ID</TableCell>
                                <TableCell sx={headerCellStyle}>Tenant</TableCell>
                                <TableCell sx={headerCellStyle}>Status</TableCell>
                                <TableCell sx={headerCellStyle}>Count</TableCell>
                                <TableCell sx={headerCellStyle}>Timestamp</TableCell>
                                <TableCell sx={{ ...headerCellStyle, textAlign: 'right' }}>Action</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {items.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} align="center" sx={{ py: 10, borderBottom: 'none' }}>
                                        <Typography variant="body2" sx={{ color: '#64748B', fontWeight: 700 }}>No record found.</Typography>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                items.map((row) => {
                                    const styles = getStatusStyles(row.status);
                                    const tenantName = row.tenant?.trade_name || 'System';
                                    return (
                                        <TableRow key={row.id} hover sx={{ '&:hover': { bgcolor: '#F8FAFC' } }}>
                                            <TableCell sx={bodyCellStyle}>
                                                <Stack direction="row" spacing={1} alignItems="center">
                                                    <Typography variant="body2" sx={{ color: '#64748B', fontWeight: 700, fontSize: '0.75rem', fontFamily: 'monospace' }}>
                                                        {row.submission_uuid?.substring(0, 13)}...
                                                    </Typography>
                                                    <IconButton size="small" onClick={() => copyToClipboard(row.submission_uuid)} sx={{ color: '#94A3B8', p: 0.5 }}>
                                                        <ContentCopyIcon sx={{ fontSize: 14 }} />
                                                    </IconButton>
                                                </Stack>
                                            </TableCell>
                                            <TableCell sx={{ ...bodyCellStyle, fontWeight: 800, color: '#1E293B' }}>
                                                {row.terminal?.serial_number || 'N/A'}
                                            </TableCell>
                                            <TableCell sx={bodyCellStyle}>
                                                <Stack direction="row" spacing={1.5} alignItems="center">
                                                    <Avatar 
                                                        sx={{ 
                                                            width: 24, 
                                                            height: 24, 
                                                            fontSize: '0.65rem', 
                                                            fontWeight: 900, 
                                                            bgcolor: getTenantColor(tenantName)
                                                        }}
                                                    >
                                                        {tenantName.charAt(0)}
                                                    </Avatar>
                                                    <Typography variant="body2" sx={{ fontWeight: 800, fontSize: '0.85rem', color: '#1E293B' }}>
                                                        {tenantName}
                                                    </Typography>
                                                </Stack>
                                            </TableCell>
                                            <TableCell sx={bodyCellStyle}>
                                                <Box sx={{ 
                                                    display: 'inline-block', 
                                                    px: 2, 
                                                    py: 0.5, 
                                                    borderRadius: 1.5,
                                                    ...styles,
                                                    fontSize: '0.65rem',
                                                    fontWeight: 900,
                                                    letterSpacing: '0.05em'
                                                }}>
                                                    {row.status?.toUpperCase() || 'UNKNOWN'}
                                                </Box>
                                            </TableCell>
                                            <TableCell sx={{ ...bodyCellStyle, color: '#475569', fontWeight: 700 }}>
                                                {row.transaction_count || 0}
                                            </TableCell>
                                            <TableCell sx={{ ...bodyCellStyle, color: '#64748B', fontSize: '0.75rem', fontWeight: 700 }}>
                                                {row.created_at ? formatDistanceToNow(new Date(row.created_at), { addSuffix: true }).toUpperCase() : '-'}
                                            </TableCell>
                                            <TableCell sx={{ ...bodyCellStyle, textAlign: 'right' }}>
                                                <Button
                                                    variant="outlined"
                                                    size="small"
                                                    onClick={() => handleShowDetails(row)}
                                                    sx={{
                                                        borderColor: '#E2E8F0',
                                                        color: '#1E293B',
                                                        textTransform: 'none',
                                                        fontWeight: 800,
                                                        borderRadius: 2,
                                                        fontSize: '0.75rem',
                                                        px: 2.5,
                                                        '&:hover': { borderColor: '#1D439B', color: '#1D439B', bgcolor: 'rgba(29, 67, 155, 0.05)' }
                                                    }}
                                                >
                                                    View Details
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </TableContainer>

                {/* Pagination Footer */}
                <Box sx={{ 
                    p: 3, 
                    display: 'flex', 
                    justifyContent: 'space-between', 
                    alignItems: 'center', 
                    bgcolor: '#F8FAFC',
                    borderTop: '1px solid #F1F5F9'
                }}>
                    <Typography variant="caption" sx={{ color: '#64748B', fontWeight: 700 }}>
                        Showing <span style={{ color: '#1E293B' }}>{from}</span> to <span style={{ color: '#1E293B' }}>{to}</span> of <span style={{ color: '#1E293B' }}>{total.toLocaleString()}</span> submissions
                    </Typography>
                    <Pagination
                        count={meta.last_page || 1}
                        page={meta.current_page || 1}
                        onChange={(e, p) => onPageChange(p)}
                        size="small"
                        sx={{
                            '& .MuiPaginationItem-root': {
                                color: '#64748B',
                                fontWeight: 800,
                                borderRadius: 1.5,
                                '&.Mui-selected': {
                                    bgcolor: '#1D439B',
                                    color: '#FFFFFF',
                                    '&:hover': { bgcolor: '#153170' }
                                },
                            },
                        }}
                    />
                </Box>
            </Paper>

            <Dialog
                open={detailOpen}
                onClose={handleCloseDetails}
                maxWidth="md"
                fullWidth
                PaperProps={{
                    sx: { borderRadius: 4, overflow: 'hidden' }
                }}
            >
                <DialogTitle sx={{ fontWeight: 800, fontSize: '0.95rem', bgcolor: '#FFFFFF', py: 2.5 }}>
                    Submission Event Details
                </DialogTitle>
                <DialogContent dividers sx={{ bgcolor: '#0B1120', p: 0 }}>
                    <Box
                        sx={{
                            color: '#e2e8f0',
                            fontFamily: 'monospace',
                            fontSize: '0.75rem',
                            maxHeight: 480,
                            overflow: 'auto',
                            p: 3
                        }}
                    >
                        <pre style={{ margin: 0 }}>
                            {detailLog
                                ? JSON.stringify(
                                      detailLog.context ||
                                          detailLog.payload ||
                                          detailLog.old_values ||
                                          detailLog,
                                      null,
                                      2
                                  )
                                : '// Select a row to inspect full payload and metadata'}
                        </pre>
                    </Box>
                </DialogContent>
                <DialogActions sx={{ px: 3, py: 2, bgcolor: '#FFFFFF' }}>
                    <Button 
                        onClick={handleCloseDetails} 
                        variant="contained" 
                        size="small"
                        sx={{
                            borderRadius: 2.5,
                            textTransform: 'none',
                            fontWeight: 800,
                            px: 3,
                            bgcolor: '#1D439B',
                            '&:hover': { bgcolor: '#153170' }
                        }}
                    >
                        Close Details
                    </Button>
                </DialogActions>
            </Dialog>
        </Stack>
    );
};

export default SubmissionEventsTable;
