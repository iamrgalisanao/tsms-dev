import React, { useState } from 'react';
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
    Collapse,
    IconButton,
    Stack,
    CircularProgress,
    Pagination,
    Divider,
    Tooltip,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button
} from '@mui/material';
import { format } from 'date-fns';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import CodeIcon from '@mui/icons-material/Code';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const LogRow = ({ log, type, onShowDetails }) => {
    const [open, setOpen] = useState(false);

    const getSeverityColor = (severity) => {
        switch (severity?.toLowerCase()) {
            case 'error': return 'error';
            case 'warning': return 'warning';
            case 'info': return 'info';
            case 'success': return 'success';
            default: return 'default';
        }
    };

    const cellStyles = {
        fontSize: '0.8rem',
        color: 'text.primary',
        py: 1.5,
        borderBottom: '1px solid rgba(0,0,0,0.03)'
    };

    const submissionStatus = (log.status || '').toUpperCase();
    const submissionTenantId = log.tenant?.trade_name || log.tenant_id || log.context?.tenant_id;
    const submissionTerminalId =
        log.terminal?.serial_number || log.terminal_id || log.context?.terminal_id;
    const submissionReason = log.reason_code || log.reason_details;
    const submissionTxnCount =
        typeof log.transaction_count !== 'undefined' ? log.transaction_count : log.context?.transaction_count;

    // Health monitoring helpers (tenant inactivity + idle monitor)
    const isHealthLog =
        type === 'system' &&
        (
            (log.message || '').startsWith('Tenant inactivity') ||
            (log.message || '').startsWith('Idle monitor') ||
            (log.log_type || '').includes('TENANT_INACTIVITY') ||
            (log.log_type || '').includes('IDLE_MONITOR') ||
            (log.type || '').includes('tenant_inactivity') ||
            (log.type || '').includes('terminal_heartbeat')
        );

    const healthContext = log.context || {};
    const healthTenantLabel = healthContext.tenant_name || submissionTenantId || healthContext.tenant_id;
    const healthInactiveMinutes = healthContext.inactive_minutes;
    const healthLastTxnAt = healthContext.last_transaction_at;
    const healthActiveTerminals = healthContext.active_terminal_count;

    return (
        <>
            <TableRow hover sx={{ '&:last-child td, &:last-child th': { border: 0 } }}>
                <TableCell sx={{ width: 40, py: 1.5 }}>
                    <IconButton size="small" onClick={() => setOpen(!open)}>
                        {open ? <KeyboardArrowUpIcon /> : <KeyboardArrowDownIcon />}
                    </IconButton>
                </TableCell>
                <TableCell sx={cellStyles}>
                    <Typography variant="body2" sx={{ fontWeight: 700 }}>
                        {log.created_at ? format(new Date(log.created_at), 'MMM dd, HH:mm:ss') : '-'}
                    </Typography>
                </TableCell>

                {type === 'system' && (
                    <>
                        <TableCell sx={cellStyles}>
                            <Chip
                                label={log.severity?.toUpperCase() || 'INFO'}
                                color={getSeverityColor(log.severity)}
                                size="small"
                                sx={{ fontWeight: 800, fontSize: '0.6rem', height: 20, borderRadius: 1.5 }}
                            />
                        </TableCell>
                        <TableCell sx={cellStyles}>
                            {isHealthLog ? 'HEALTH' : (log.log_type || 'SYSTEM')}
                        </TableCell>
                        <TableCell sx={cellStyles}>
                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                {log.message}
                            </Typography>
                            {isHealthLog && (
                                <Typography
                                    variant="caption"
                                    sx={{ display: 'block', color: 'text.secondary', mt: 0.25 }}
                                >
                                    {healthTenantLabel && (
                                        <>
                                            Tenant: <strong>{healthTenantLabel}</strong>
                                            {"  "}
                                        </>
                                    )}
                                    {typeof healthInactiveMinutes !== 'undefined' && (
                                        <>· Inactive {healthInactiveMinutes} min{"  "}</>
                                    )}
                                    {healthLastTxnAt && (
                                        <>· Last txn at {healthLastTxnAt}{"  "}</>
                                    )}
                                    {typeof healthActiveTerminals !== 'undefined' && (
                                        <>· Active terminals: {healthActiveTerminals}</>
                                    )}
                                </Typography>
                            )}
                        </TableCell>
                    </>
                )}

                {type === 'audit' && (
                    <>
                        <TableCell sx={cellStyles}>
                            <Chip label={log.action_type || 'ACTION'} size="small" variant="outlined" sx={{ fontWeight: 800, fontSize: '0.6rem', height: 20 }} />
                        </TableCell>
                        <TableCell sx={cellStyles}>{log.user?.name || 'System'}</TableCell>
                        <TableCell sx={cellStyles}>
                            <Typography variant="body2" sx={{ fontWeight: 600 }}>{log.action}</Typography>
                        </TableCell>
                    </>
                )}

                {type === 'webhook' && (
                    <>
                        <TableCell sx={cellStyles}>
                            <Chip
                                label={log.status || 'PENDING'}
                                color={log.status === 'SUCCESS' ? 'success' : 'error'}
                                size="small"
                                sx={{ fontWeight: 800, fontSize: '0.6rem', height: 20 }}
                            />
                        </TableCell>
                        <TableCell sx={cellStyles}>{log.terminal?.serial_number || 'Global'}</TableCell>
                        <TableCell sx={cellStyles}>
                            <Typography variant="body2" sx={{ fontWeight: 600 }}>{log.endpoint}</Typography>
                        </TableCell>
                    </>
                )}
            </TableRow>
            <TableRow>
                <TableCell sx={{ py: 0 }} colSpan={6}>
                    <Collapse in={open} timeout="auto" unmountOnExit>
                        <Box sx={{ p: 3, bgcolor: 'grey.50', borderLeft: '4px solid', borderColor: 'primary.main', mb: 2, borderRadius: '0 0 8px 8px' }}>
                            {type === 'submission' && (
                                <Box
                                    sx={{
                                        mb: 2,
                                        p: 2,
                                        borderRadius: 2,
                                        bgcolor: 'white',
                                        border: '1px solid',
                                        borderColor: 'divider'
                                    }}
                                >
                                    <Typography
                                        variant="overline"
                                        sx={{ fontWeight: 900, letterSpacing: '0.12em', color: 'text.secondary' }}
                                    >
                                        Submission Overview
                                    </Typography>
                                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} sx={{ mt: 1 }}>
                                        <Box>
                                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                Submission ID
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                                {log.submission_uuid || '—'}
                                            </Typography>
                                        </Box>
                                        <Box>
                                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                Tenant
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                                {submissionTenantId || 'Unknown tenant'}
                                            </Typography>
                                        </Box>
                                        <Box>
                                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                Terminal
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                                {submissionTerminalId || 'Unknown terminal'}
                                            </Typography>
                                        </Box>
                                        <Box>
                                            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                Status
                                            </Typography>
                                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                                {submissionStatus || 'UNKNOWN'}
                                            </Typography>
                                        </Box>
                                        {typeof submissionTxnCount !== 'undefined' && (
                                            <Box>
                                                <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                                                    Transactions
                                                </Typography>
                                                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                                                    {submissionTxnCount}
                                                </Typography>
                                            </Box>
                                        )}
                                    </Stack>
                                </Box>
                            )}

                            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 2 }}>
                                <CodeIcon fontSize="small" sx={{ color: 'primary.main' }} />
                                <Typography variant="caption" sx={{ fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                                    Context Metadata & Payload
                                </Typography>
                                {type === 'submission' && (
                                    <Tooltip
                                        placement="right"
                                        title={
                                            <Box sx={{ maxWidth: 360 }}>
                                                <Typography
                                                    variant="caption"
                                                    sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', mb: 0.5 }}
                                                >
                                                    Submission Insight
                                                </Typography>
                                                <Typography variant="body2" sx={{ fontSize: '0.75rem' }}>
                                                    Status: {submissionStatus || 'UNKNOWN'} · Tenant {submissionTenantId || 'n/a'} ·
                                                    Terminal {submissionTerminalId || 'n/a'}
                                                </Typography>
                                                {typeof submissionTxnCount !== 'undefined' && (
                                                    <Typography variant="body2" sx={{ fontSize: '0.75rem' }}>
                                                        Transactions in envelope: {submissionTxnCount}
                                                    </Typography>
                                                )}
                                                {submissionReason && (
                                                    <Typography variant="body2" sx={{ fontSize: '0.75rem', mt: 0.5 }}>
                                                        Reason: {submissionReason}
                                                    </Typography>
                                                )}
                                                <Typography variant="body2" sx={{ fontSize: '0.75rem', mt: 0.5 }}>
                                                    Each row represents a single submission (submission_uuid) that may contain
                                                    one or more transactions. COMPLETED with no reason indicates the payload
                                                    passed validation; FAILED/REJECTED or a populated reason indicates
                                                    validation or processing issues.
                                                </Typography>
                                            </Box>
                                        }
                                    >
                                        <IconButton size="small" sx={{ ml: 0.5 }}>
                                            <InfoOutlinedIcon sx={{ fontSize: 16, opacity: 0.7 }} />
                                        </IconButton>
                                    </Tooltip>
                                )}
                            </Stack>
                            <Box
                                sx={{
                                    bgcolor: '#1a202c',
                                    color: '#e2e8f0',
                                    p: 2,
                                    borderRadius: 3,
                                    fontFamily: 'monospace',
                                    fontSize: '0.75rem',
                                    overflowX: 'auto',
                                    boxShadow: 'inset 0 2px 10px rgba(0,0,0,0.2)',
                                    cursor: 'pointer',
                                }}
                                onClick={() => onShowDetails && onShowDetails(log)}
                            >
                                <pre>{JSON.stringify(log.context || log.payload || log.old_values || log, null, 2)}</pre>
                            </Box>
                        </Box>
                    </Collapse>
                </TableCell>
            </TableRow>
        </>
    );
};

const LogTable = ({ data, loading, type, onPageChange }) => {
    const headerStyles = {
        fontWeight: 800,
        fontSize: '0.65rem',
        textTransform: 'uppercase',
        letterSpacing: '0.1em',
        color: '#EB342E',
        py: 2,
        bgcolor: 'white'
    };

    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLog, setDetailLog] = useState(null);

    const handleShowDetails = (log) => {
        setDetailLog(log);
        setDetailOpen(true);
    };

    const handleCloseDetails = () => {
        setDetailOpen(false);
    };

    if (loading) {
        return (
            <Box sx={{ py: 20, textAlign: 'center' }}>
                <CircularProgress size={32} />
                <Typography variant="body2" sx={{ mt: 2, fontWeight: 700, color: 'text.secondary' }}>Loading Logs...</Typography>
            </Box>
        );
    }

    const items = data?.data || [];
    const meta = data || {};

    const from = meta.from || 0;
    const to = meta.to || 0;
    const total = meta.total || items.length || 0;

    return (
        <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 4, overflow: 'hidden' }}>
            <TableContainer>
                <Table size="small">
                    <TableHead>
                        <TableRow>
                            <TableCell sx={headerStyles} />
                            <TableCell sx={headerStyles}>Timestamp</TableCell>
                            {type === 'system' && (
                                <>
                                    <TableCell sx={headerStyles}>Severity</TableCell>
                                    <TableCell sx={headerStyles}>Category</TableCell>
                                    <TableCell sx={headerStyles}>Event Message</TableCell>
                                </>
                            )}
                            {type === 'audit' && (
                                <>
                                    <TableCell sx={headerStyles}>Type</TableCell>
                                    <TableCell sx={headerStyles}>Actor</TableCell>
                                    <TableCell sx={headerStyles}>Action Description</TableCell>
                                </>
                            )}
                            {type === 'webhook' && (
                                <>
                                    <TableCell sx={headerStyles}>Status</TableCell>
                                    <TableCell sx={headerStyles}>Terminal UID</TableCell>
                                    <TableCell sx={headerStyles}>Endpoint Reference</TableCell>
                                </>
                            )}
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {items.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={6} align="center" sx={{ py: 10 }}>
                                    <Typography variant="body2" sx={{ fontWeight: 700, opacity: 0.5 }}>No records found in this sequence.</Typography>
                                </TableCell>
                            </TableRow>
                        ) : (
                            items.map((log) => (
                                <LogRow key={log.id} log={log} type={type} onShowDetails={handleShowDetails} />
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
                    rowGap: 1,
                }}
            >
                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                    {total > 0
                        ? `Showing ${from}-${to} of ${total} event${total !== 1 ? 's' : ''}`
                        : 'No events in the current view'}
                </Typography>
                <Pagination
                    count={meta.last_page || 1}
                    page={meta.current_page || 1}
                    onChange={(e, p) => onPageChange(p)}
                    color="primary"
                    size="small"
                />
            </Box>

            <Dialog
                open={detailOpen}
                onClose={handleCloseDetails}
                maxWidth="md"
                fullWidth
            >
                <DialogTitle sx={{ fontWeight: 800, fontSize: '0.95rem' }}>
                    {type === 'system' && 'System Log Details'}
                    {type === 'audit' && 'Audit Entry Details'}
                    {type === 'webhook' && 'Webhook Event Details'}
                    {type === 'submission' && 'Submission Event Details'}
                </DialogTitle>
                <DialogContent dividers sx={{ bgcolor: '#0b1120' }}>
                    <Box
                        sx={{
                            bgcolor: '#0b1120',
                            color: '#e2e8f0',
                            fontFamily: 'monospace',
                            fontSize: '0.75rem',
                            maxHeight: 480,
                            overflow: 'auto',
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
                <DialogActions>
                    <Button onClick={handleCloseDetails} color="primary" variant="contained" size="small">
                        Close
                    </Button>
                </DialogActions>
            </Dialog>
        </Paper>
    );
};

export default LogTable;
