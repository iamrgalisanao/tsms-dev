import React, { useState } from 'react';
import {
    Box,
    Paper,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Chip,
    Typography,
    Stack,
    Tooltip,
    Divider,
    Pagination,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button,
    IconButton,
    Snackbar,
    Alert
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import CheckIcon from '@mui/icons-material/Check';
import StoreIcon from '@mui/icons-material/Store';
import { format } from 'date-fns';

const headerStyles = {
    fontWeight: 800,
    fontSize: '0.65rem',
    textTransform: 'uppercase',
    letterSpacing: '0.1em',
    color: '#EB342E',
    py: 2,
    bgcolor: 'white'
};

const IncidentsTable = ({ data, loading, onPageChange }) => {
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailIncident, setDetailIncident] = useState(null);
    const [copied, setCopied] = useState(false);
    const [snackOpen, setSnackOpen] = useState(false);

    if (loading && !data) {
        return (
            <Box sx={{ py: 20, textAlign: 'center' }}>
                <Typography variant="body2" sx={{ mt: 2, fontWeight: 700, color: 'text.secondary' }}>
                    Loading incidents...
                </Typography>
            </Box>
        );
    }

    const items = data?.data || [];
    const meta = data?.meta || {};

    const from = items.length > 0 && meta.current_page && meta.per_page
        ? (meta.current_page - 1) * meta.per_page + 1
        : 0;
    const to = items.length > 0 && from > 0
        ? from + items.length - 1
        : 0;
    const total = meta.total || items.length || 0;

    const stateColor = (state) => {
        switch ((state || '').toUpperCase()) {
            case 'OPEN':       return 'error';
            case 'IN_PROGRESS': return 'warning';
            case 'RESOLVED':   return 'success';
            default:           return 'default';
        }
    };

    const handleShowDetails = (incident) => {
        setDetailIncident(incident);
        setDetailOpen(true);
        setCopied(false);
    };

    const handleCloseDetails = () => {
        setDetailOpen(false);
    };

    const handleCopy = () => {
        if (!detailIncident) return;
        const text = JSON.stringify(detailIncident.context || detailIncident, null, 2);
        navigator.clipboard.writeText(text).then(() => {
            setCopied(true);
            setSnackOpen(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 4, overflow: 'hidden' }}>
            <TableContainer>
                <Table size="small">
                    <TableHead>
                        <TableRow>
                            <TableCell sx={headerStyles}>First Seen</TableCell>
                            <TableCell sx={headerStyles}>State</TableCell>
                            <TableCell sx={headerStyles}>Category</TableCell>
                            <TableCell sx={headerStyles}>Tenant</TableCell>
                            <TableCell sx={headerStyles}>Terminal</TableCell>
                            <TableCell sx={headerStyles}>Reason</TableCell>
                            <TableCell sx={headerStyles}>Summary</TableCell>
                            <TableCell sx={headerStyles} align="right">Failures</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {items.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={8} align="center" sx={{ py: 10 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 700, opacity: 0.5 }}>
                                        No incidents found for this range.
                                    </Typography>
                                </TableCell>
                            </TableRow>
                        ) : (
                            items.map((incident) => (
                                <TableRow
                                    key={incident.id}
                                    hover
                                    onClick={() => handleShowDetails(incident)}
                                    sx={{ cursor: 'pointer' }}
                                >
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                            {incident.first_seen_at
                                                ? format(new Date(incident.first_seen_at), 'MMM dd, HH:mm:ss')
                                                : '—'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Chip
                                            label={incident.state || 'OPEN'}
                                            color={stateColor(incident.state)}
                                            size="small"
                                            sx={{ fontWeight: 800, fontSize: '0.6rem', height: 20, borderRadius: 1.5 }}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="caption" sx={{ fontWeight: 700, textTransform: 'uppercase' }}>
                                            {incident.category || 'UNCLASSIFIED'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        {/* Show tenant name instead of raw ID */}
                                        <Stack direction="row" alignItems="center" spacing={0.75}>
                                            <StoreIcon sx={{ fontSize: 13, color: 'primary.main', opacity: 0.7 }} />
                                            <Tooltip title={`Tenant ID: ${incident.tenant_id ?? '—'}`} placement="top" arrow>
                                                <Typography
                                                    variant="body2"
                                                    sx={{
                                                        fontWeight: 600,
                                                        maxWidth: 120,
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap'
                                                    }}
                                                >
                                                    {incident.tenant_name || `Tenant #${incident.tenant_id}` || '—'}
                                                </Typography>
                                            </Tooltip>
                                        </Stack>
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 500, fontFamily: 'monospace', fontSize: '0.72rem' }}>
                                            {incident.terminal_id || '—'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 500 }}>
                                            {incident.reason_code || '—'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Tooltip title={incident.human_message || ''} placement="top" arrow>
                                            <Typography
                                                variant="body2"
                                                sx={{ fontWeight: 600, maxWidth: 260, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
                                            >
                                                {incident.human_title || incident.human_message || '—'}
                                            </Typography>
                                        </Tooltip>
                                    </TableCell>
                                    <TableCell align="right">
                                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                            {incident.failed_count ?? 0}
                                            {typeof incident.occurrence_count !== 'undefined' && (
                                                <Typography component="span" variant="caption" sx={{ ml: 0.5, color: 'text.secondary' }}>
                                                    ({incident.occurrence_count} hits)
                                                </Typography>
                                            )}
                                        </Typography>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </TableContainer>

            <Divider />

            <Box
                sx={{
                    p: 2,
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    bgcolor: 'grey.50',
                    flexWrap: 'wrap',
                    rowGap: 1
                }}
            >
                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                    {total > 0
                        ? `Showing ${from}-${to} of ${total} incident${total !== 1 ? 's' : ''}`
                        : 'No incidents in the current view'}
                </Typography>
                <Pagination
                    count={meta.last_page || 1}
                    page={meta.current_page || 1}
                    onChange={(e, p) => onPageChange && onPageChange(p)}
                    color="primary"
                    size="small"
                />
            </Box>

            {/* Incident Detail Dialog */}
            <Dialog open={detailOpen} onClose={handleCloseDetails} maxWidth="md" fullWidth>
                <DialogTitle
                    sx={{
                        fontWeight: 800,
                        fontSize: '0.95rem',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        bgcolor: '#0b1120',
                        color: '#e2e8f0',
                        borderBottom: '1px solid rgba(255,255,255,0.08)',
                        py: 1.5,
                        px: 2.5
                    }}
                >
                    <Stack direction="row" alignItems="center" spacing={1.5}>
                        <Box
                            sx={{
                                bgcolor: 'rgba(235,52,46,0.15)',
                                borderRadius: 1.5,
                                px: 1,
                                py: 0.3,
                                display: 'flex',
                                alignItems: 'center'
                            }}
                        >
                            <Typography variant="caption" sx={{ color: '#EB342E', fontWeight: 800, fontFamily: 'monospace' }}>
                                INC
                            </Typography>
                        </Box>
                        <Box>
                            <Typography sx={{ fontWeight: 800, fontSize: '0.88rem', color: '#e2e8f0' }}>
                                Incident Details
                            </Typography>
                            {detailIncident && (
                                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.45)', fontFamily: 'monospace', fontSize: '0.68rem' }}>
                                    {detailIncident.tenant_name || `Tenant #${detailIncident.tenant_id}`}
                                    {' · '}
                                    Terminal {detailIncident.terminal_id}
                                    {' · '}
                                    {detailIncident.category}
                                </Typography>
                            )}
                        </Box>
                    </Stack>

                    {/* Copy Button */}
                    <Tooltip title={copied ? 'Copied!' : 'Copy log to clipboard'} placement="left">
                        <IconButton
                            onClick={handleCopy}
                            size="small"
                            sx={{
                                color: copied ? '#4ade80' : 'rgba(255,255,255,0.6)',
                                bgcolor: copied ? 'rgba(74,222,128,0.1)' : 'rgba(255,255,255,0.05)',
                                border: '1px solid',
                                borderColor: copied ? 'rgba(74,222,128,0.3)' : 'rgba(255,255,255,0.1)',
                                borderRadius: 1.5,
                                transition: 'all 0.2s ease',
                                '&:hover': {
                                    bgcolor: 'rgba(255,255,255,0.1)',
                                    color: 'white'
                                }
                            }}
                        >
                            {copied ? <CheckIcon fontSize="small" /> : <ContentCopyIcon fontSize="small" />}
                        </IconButton>
                    </Tooltip>
                </DialogTitle>

                <DialogContent dividers sx={{ bgcolor: '#0b1120', p: 0 }}>
                    <Box
                        sx={{
                            bgcolor: '#0b1120',
                            color: '#e2e8f0',
                            fontFamily: '"JetBrains Mono", "Fira Code", "Cascadia Code", monospace',
                            fontSize: '0.73rem',
                            lineHeight: 1.7,
                            maxHeight: 500,
                            overflow: 'auto',
                            p: 2.5
                        }}
                    >
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                            {detailIncident
                                ? JSON.stringify(detailIncident.context || detailIncident, null, 2)
                                : '// Select an incident to inspect full context'}
                        </pre>
                    </Box>
                </DialogContent>

                <DialogActions sx={{ bgcolor: '#0d1527', borderTop: '1px solid rgba(255,255,255,0.08)', px: 2.5, py: 1.25 }}>
                    <Stack direction="row" spacing={1.5} alignItems="center" width="100%">
                        {detailIncident?.pos_action && (
                            <Box
                                sx={{
                                    flex: 1,
                                    bgcolor: 'rgba(251,191,36,0.08)',
                                    border: '1px solid rgba(251,191,36,0.2)',
                                    borderRadius: 1.5,
                                    px: 1.5,
                                    py: 0.75
                                }}
                            >
                                <Typography variant="caption" sx={{ color: '#fbbf24', fontWeight: 700, display: 'block', mb: 0.2 }}>
                                    Recommended Action
                                </Typography>
                                <Typography variant="caption" sx={{ color: 'rgba(251,191,36,0.8)' }}>
                                    {detailIncident.pos_action}
                                </Typography>
                            </Box>
                        )}
                        <Box sx={{ ml: 'auto' }}>
                            <Button
                                onClick={handleCopy}
                                startIcon={copied ? <CheckIcon /> : <ContentCopyIcon />}
                                variant="outlined"
                                size="small"
                                sx={{
                                    mr: 1,
                                    color: copied ? '#4ade80' : 'rgba(255,255,255,0.6)',
                                    borderColor: copied ? 'rgba(74,222,128,0.4)' : 'rgba(255,255,255,0.2)',
                                    textTransform: 'none',
                                    fontWeight: 700,
                                    fontSize: '0.75rem',
                                    '&:hover': { borderColor: 'rgba(255,255,255,0.5)', bgcolor: 'rgba(255,255,255,0.05)' }
                                }}
                            >
                                {copied ? 'Copied!' : 'Copy Log'}
                            </Button>
                            <Button
                                onClick={handleCloseDetails}
                                variant="contained"
                                size="small"
                                sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}
                            >
                                Close
                            </Button>
                        </Box>
                    </Stack>
                </DialogActions>
            </Dialog>

            {/* Copy success snackbar */}
            <Snackbar
                open={snackOpen}
                autoHideDuration={2000}
                onClose={() => setSnackOpen(false)}
                anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
            >
                <Alert
                    onClose={() => setSnackOpen(false)}
                    severity="success"
                    variant="filled"
                    sx={{ fontWeight: 700, fontSize: '0.8rem' }}
                >
                    Log copied to clipboard
                </Alert>
            </Snackbar>
        </Paper>
    );
};

export default IncidentsTable;
