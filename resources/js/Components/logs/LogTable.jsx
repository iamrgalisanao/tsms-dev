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
    Tooltip
} from '@mui/material';
import { format } from 'date-fns';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import CodeIcon from '@mui/icons-material/Code';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const LogRow = ({ log, type }) => {
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
                        <TableCell sx={cellStyles}>{log.log_type || 'SYSTEM'}</TableCell>
                        <TableCell sx={cellStyles}>
                            <Typography variant="body2" sx={{ fontWeight: 600 }}>{log.message}</Typography>
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
                            <Box sx={{
                                bgcolor: '#1a202c',
                                color: '#e2e8f0',
                                p: 2,
                                borderRadius: 3,
                                fontFamily: 'monospace',
                                fontSize: '0.75rem',
                                overflowX: 'auto',
                                boxShadow: 'inset 0 2px 10px rgba(0,0,0,0.2)'
                            }}>
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
                                <LogRow key={log.id} log={log} type={type} />
                            ))
                        )}
                    </TableBody>
                </Table>
            </TableContainer>

            <Divider />

            <Box sx={{ p: 2, display: 'flex', justifyContent: 'center', bgcolor: 'grey.50' }}>
                <Pagination
                    count={meta.last_page || 1}
                    page={meta.current_page || 1}
                    onChange={(e, p) => onPageChange(p)}
                    color="primary"
                    size="small"
                />
            </Box>
        </Paper>
    );
};

export default LogTable;
