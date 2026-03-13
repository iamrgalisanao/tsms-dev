import React, { useState } from 'react';
import {
    Box, Paper, Table, TableBody, TableCell, TableContainer,
    TableHead, TableRow, Typography, Stack, Chip, Tooltip,
    IconButton, Button, Dialog, DialogTitle, DialogContent,
    DialogActions, Divider, Pagination, CircularProgress,
    Alert
} from '@mui/material';
import ReplayIcon from '@mui/icons-material/Replay';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import CheckIcon from '@mui/icons-material/Check';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import { dlqService } from '../../services/dlqService';

const headerStyles = {
    fontWeight: 800,
    fontSize: '0.65rem',
    textTransform: 'uppercase',
    letterSpacing: '0.1em',
    color: '#EB342E',
    py: 2,
    bgcolor: 'white',
};

const FailedJobsTable = ({ data, loading, onPageChange, onRefresh }) => {
    const [detailJob, setDetailJob] = useState(null);
    const [copied, setCopied] = useState(false);
    const [actionLoading, setActionLoading] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [actionSuccess, setActionSuccess] = useState(null);

    const items = data?.data || [];
    const meta  = data?.meta  || {};
    const total = meta.total  || 0;
    const from  = total > 0 && meta.current_page && meta.per_page
        ? (meta.current_page - 1) * meta.per_page + 1
        : 0;
    const to = from > 0 ? from + items.length - 1 : 0;

    const handleRetry = async (uuid, e) => {
        e?.stopPropagation();
        setActionLoading(uuid);
        setActionError(null);
        setActionSuccess(null);
        try {
            await dlqService.retry(uuid);
            setActionSuccess(`Job ${uuid.slice(0, 8)}… queued for retry.`);
            onRefresh?.();
        } catch (err) {
            setActionError(err?.response?.data?.message || 'Retry failed.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleFlush = async (uuid, e) => {
        e?.stopPropagation();
        if (!window.confirm('Permanently delete this failed job? This cannot be undone.')) return;
        setActionLoading(uuid);
        setActionError(null);
        try {
            await dlqService.flush(uuid);
            setActionSuccess(`Job deleted from DLQ.`);
            onRefresh?.();
        } catch (err) {
            setActionError(err?.response?.data?.message || 'Delete failed.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleRetryAll = async () => {
        if (!window.confirm(`Re-queue ALL ${total} failed jobs? This may cause high load.`)) return;
        setActionLoading('all');
        try {
            await dlqService.retryAll();
            setActionSuccess(`All ${total} jobs queued for retry.`);
            onRefresh?.();
        } catch (err) {
            setActionError(err?.response?.data?.message || 'Retry all failed.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleCopy = () => {
        if (!detailJob) return;
        navigator.clipboard.writeText(
            JSON.stringify({ exception: detailJob.exception, payload: detailJob.payload }, null, 2)
        ).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    const ageColor = (minutes) => {
        if (minutes < 30) return 'success';
        if (minutes < 120) return 'warning';
        return 'error';
    };

    if (loading && !data) {
        return (
            <Box sx={{ py: 20, textAlign: 'center' }}>
                <CircularProgress size={24} />
                <Typography variant="body2" sx={{ mt: 2, fontWeight: 700, color: 'text.secondary' }}>
                    Loading failed jobs…
                </Typography>
            </Box>
        );
    }

    return (
        <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 4, overflow: 'hidden' }}>

            {/* Action feedback */}
            {(actionError || actionSuccess) && (
                <Box sx={{ p: 2, pb: 0 }}>
                    {actionError   && <Alert severity="error"   onClose={() => setActionError(null)}   sx={{ mb: 1 }}>{actionError}</Alert>}
                    {actionSuccess && <Alert severity="success" onClose={() => setActionSuccess(null)} sx={{ mb: 1 }}>{actionSuccess}</Alert>}
                </Box>
            )}

            {/* Toolbar */}
            {total > 0 && (
                <Box sx={{ px: 2, pt: 2, pb: 1, display: 'flex', justifyContent: 'flex-end' }}>
                    <Button
                        size="small"
                        startIcon={actionLoading === 'all' ? <CircularProgress size={14} /> : <ReplayIcon />}
                        variant="outlined"
                        color="warning"
                        disabled={!!actionLoading}
                        onClick={handleRetryAll}
                        sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}
                    >
                        Retry All ({total})
                    </Button>
                </Box>
            )}

            <TableContainer>
                <Table size="small">
                    <TableHead>
                        <TableRow>
                            <TableCell sx={headerStyles}>Failed At</TableCell>
                            <TableCell sx={headerStyles}>Age</TableCell>
                            <TableCell sx={headerStyles}>Queue</TableCell>
                            <TableCell sx={headerStyles}>Job Class</TableCell>
                            <TableCell sx={headerStyles}>Error Preview</TableCell>
                            <TableCell sx={headerStyles} align="right">Actions</TableCell>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {items.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={6} align="center" sx={{ py: 10 }}>
                                    <Stack alignItems="center" spacing={1}>
                                        <CheckIcon sx={{ fontSize: 32, color: 'success.main', opacity: 0.6 }} />
                                        <Typography variant="body2" sx={{ fontWeight: 700, opacity: 0.5 }}>
                                            Dead-letter queue is empty — all jobs processed successfully.
                                        </Typography>
                                    </Stack>
                                </TableCell>
                            </TableRow>
                        ) : (
                            items.map((job) => (
                                <TableRow
                                    key={job.uuid}
                                    hover
                                    onClick={() => setDetailJob(job)}
                                    sx={{ cursor: 'pointer' }}
                                >
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 600, fontFamily: 'monospace', fontSize: '0.72rem' }}>
                                            {job.failed_at}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Chip
                                            label={`${job.age_minutes}m ago`}
                                            color={ageColor(job.age_minutes)}
                                            size="small"
                                            sx={{ fontWeight: 800, fontSize: '0.6rem', height: 20, borderRadius: 1.5 }}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="caption" sx={{ fontWeight: 700, fontFamily: 'monospace', fontSize: '0.68rem', color: 'primary.main' }}>
                                            {job.queue}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Typography variant="caption" sx={{ fontWeight: 700 }}>
                                            {job.job_class?.split('\\').pop() || '—'}
                                        </Typography>
                                    </TableCell>
                                    <TableCell>
                                        <Tooltip title={job.exception || ''} placement="top" arrow>
                                            <Stack direction="row" spacing={0.5} alignItems="center">
                                                <ErrorOutlineIcon sx={{ fontSize: 13, color: 'error.main', flexShrink: 0 }} />
                                                <Typography
                                                    variant="caption"
                                                    sx={{ maxWidth: 260, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', color: 'error.main', fontWeight: 600 }}
                                                >
                                                    {(job.exception || '').split('\n')[0].slice(0, 120)}
                                                </Typography>
                                            </Stack>
                                        </Tooltip>
                                    </TableCell>
                                    <TableCell align="right" onClick={(e) => e.stopPropagation()}>
                                        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                                            <Tooltip title="Retry this job">
                                                <span>
                                                    <IconButton
                                                        size="small"
                                                        color="primary"
                                                        disabled={actionLoading === job.uuid}
                                                        onClick={(e) => handleRetry(job.uuid, e)}
                                                    >
                                                        {actionLoading === job.uuid
                                                            ? <CircularProgress size={14} />
                                                            : <ReplayIcon fontSize="small" />}
                                                    </IconButton>
                                                </span>
                                            </Tooltip>
                                            <Tooltip title="Delete from DLQ">
                                                <span>
                                                    <IconButton
                                                        size="small"
                                                        color="error"
                                                        disabled={!!actionLoading}
                                                        onClick={(e) => handleFlush(job.uuid, e)}
                                                    >
                                                        <DeleteOutlineIcon fontSize="small" />
                                                    </IconButton>
                                                </span>
                                            </Tooltip>
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </TableContainer>

            <Divider />

            <Box sx={{ p: 2, display: 'flex', justifyContent: 'space-between', alignItems: 'center', bgcolor: 'grey.50', flexWrap: 'wrap', rowGap: 1 }}>
                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500 }}>
                    {total > 0
                        ? `Showing ${from}–${to} of ${total} failed job${total !== 1 ? 's' : ''}`
                        : 'Dead-letter queue is clear'}
                </Typography>
                <Pagination
                    count={meta.last_page || 1}
                    page={meta.current_page || 1}
                    onChange={(_, p) => onPageChange?.(p)}
                    color="primary"
                    size="small"
                />
            </Box>

            {/* Detail dialog */}
            <Dialog open={!!detailJob} onClose={() => setDetailJob(null)} maxWidth="md" fullWidth>
                <DialogTitle sx={{
                    fontWeight: 800, fontSize: '0.95rem',
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    bgcolor: '#0b1120', color: '#e2e8f0',
                    borderBottom: '1px solid rgba(255,255,255,0.08)', py: 1.5, px: 2.5
                }}>
                    <Stack direction="row" spacing={1.5} alignItems="center">
                        <Box sx={{ bgcolor: 'rgba(235,52,46,0.15)', borderRadius: 1.5, px: 1, py: 0.3 }}>
                            <Typography variant="caption" sx={{ color: '#EB342E', fontWeight: 800, fontFamily: 'monospace' }}>DLQ</Typography>
                        </Box>
                        <Box>
                            <Typography sx={{ fontWeight: 800, fontSize: '0.88rem', color: '#e2e8f0' }}>
                                Failed Job Detail
                            </Typography>
                            {detailJob && (
                                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.45)', fontFamily: 'monospace', fontSize: '0.68rem' }}>
                                    {detailJob.job_class?.split('\\').pop()} · {detailJob.queue} · {detailJob.age_minutes}m ago
                                </Typography>
                            )}
                        </Box>
                    </Stack>
                    <Tooltip title={copied ? 'Copied!' : 'Copy exception + payload'}>
                        <IconButton
                            size="small"
                            onClick={handleCopy}
                            sx={{
                                color: copied ? '#4ade80' : 'rgba(255,255,255,0.6)',
                                bgcolor: copied ? 'rgba(74,222,128,0.1)' : 'rgba(255,255,255,0.05)',
                                border: '1px solid', borderColor: copied ? 'rgba(74,222,128,0.3)' : 'rgba(255,255,255,0.1)',
                                borderRadius: 1.5, transition: 'all 0.2s',
                            }}
                        >
                            {copied ? <CheckIcon fontSize="small" /> : <ContentCopyIcon fontSize="small" />}
                        </IconButton>
                    </Tooltip>
                </DialogTitle>

                <DialogContent dividers sx={{ bgcolor: '#0b1120', p: 0 }}>
                    <Box sx={{
                        bgcolor: '#0b1120', color: '#e2e8f0',
                        fontFamily: '"JetBrains Mono", monospace',
                        fontSize: '0.72rem', lineHeight: 1.7,
                        maxHeight: 500, overflow: 'auto', p: 2.5
                    }}>
                        <Typography variant="caption" sx={{ color: '#EB342E', fontWeight: 800, display: 'block', mb: 1 }}>
                            EXCEPTION
                        </Typography>
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: '#fca5a5', marginBottom: 24 }}>
                            {detailJob?.exception || 'No exception recorded'}
                        </pre>
                        <Typography variant="caption" sx={{ color: '#93c5fd', fontWeight: 800, display: 'block', mb: 1 }}>
                            PAYLOAD
                        </Typography>
                        <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: '#e2e8f0' }}>
                            {detailJob ? JSON.stringify(detailJob.payload, null, 2) : ''}
                        </pre>
                    </Box>
                </DialogContent>

                <DialogActions sx={{ bgcolor: '#0d1527', borderTop: '1px solid rgba(255,255,255,0.08)', px: 2.5, py: 1.25 }}>
                    <Stack direction="row" spacing={1} sx={{ ml: 'auto' }}>
                        <Button
                            startIcon={<ReplayIcon />}
                            variant="outlined"
                            size="small"
                            disabled={!!actionLoading}
                            onClick={() => { handleRetry(detailJob.uuid); setDetailJob(null); }}
                            sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem', color: 'rgba(255,255,255,0.7)', borderColor: 'rgba(255,255,255,0.2)' }}
                        >
                            Retry
                        </Button>
                        <Button
                            startIcon={<DeleteOutlineIcon />}
                            variant="outlined"
                            color="error"
                            size="small"
                            onClick={() => { handleFlush(detailJob.uuid); setDetailJob(null); }}
                            sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}
                        >
                            Delete
                        </Button>
                        <Button
                            onClick={() => setDetailJob(null)}
                            variant="contained"
                            size="small"
                            sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}
                        >
                            Close
                        </Button>
                    </Stack>
                </DialogActions>
            </Dialog>
        </Paper>
    );
};

export default FailedJobsTable;
