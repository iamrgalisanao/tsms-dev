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
    Button
} from '@mui/material';
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
            case 'OPEN':
                return 'error';
            case 'IN_PROGRESS':
                return 'warning';
            case 'RESOLVED':
                return 'success';
            default:
                return 'default';
        }
    };

    const handleShowDetails = (incident) => {
        setDetailIncident(incident);
        setDetailOpen(true);
    };

    const handleCloseDetails = () => {
        setDetailOpen(false);
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
                                        <Typography variant="body2" sx={{ fontWeight: 500 }}>
                                            {incident.tenant_id || '—'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 500 }}>
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

            <Dialog open={detailOpen} onClose={handleCloseDetails} maxWidth="md" fullWidth>
                <DialogTitle sx={{ fontWeight: 800, fontSize: '0.95rem' }}>
                    Incident Details
                </DialogTitle>
                <DialogContent dividers sx={{ bgcolor: '#0b1120' }}>
                    <Box
                        sx={{
                            bgcolor: '#0b1120',
                            color: '#e2e8f0',
                            fontFamily: 'monospace',
                            fontSize: '0.75rem',
                            maxHeight: 480,
                            overflow: 'auto'
                        }}
                    >
                        <pre style={{ margin: 0 }}>
                            {detailIncident
                                ? JSON.stringify(detailIncident.context || detailIncident, null, 2)
                                : '// Select an incident to inspect full context'}
                        </pre>
                    </Box>
                </DialogContent>
                <DialogActions>
                    <Stack direction="row" spacing={1} sx={{ px: 1, py: 0.5 }}>
                        {detailIncident?.pos_action && (
                            <Tooltip title="Recommended next action for POS or operations" placement="top">
                                <Typography
                                    variant="caption"
                                    sx={{ color: 'text.secondary', maxWidth: 360, textAlign: 'left' }}
                                >
                                    Next step: {detailIncident.pos_action}
                                </Typography>
                            </Tooltip>
                        )}
                        <Box sx={{ flexGrow: 1 }} />
                        <Button onClick={handleCloseDetails} color="primary" variant="contained" size="small">
                            Close
                        </Button>
                    </Stack>
                </DialogActions>
            </Dialog>
        </Paper>
    );
};

export default IncidentsTable;
