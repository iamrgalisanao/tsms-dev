import React, { useState } from 'react';
import {
    Box, Paper, Table, TableBody, TableCell, TableContainer,
    TableHead, TableRow, Typography, Stack, Chip, Tooltip,
    IconButton, Button, Dialog, DialogTitle, DialogContent,
    DialogActions, Divider, Pagination, CircularProgress,
    Alert, LinearProgress, MobileStepper
} from '@mui/material';
import ReplayIcon from '@mui/icons-material/Replay';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import CheckIcon from '@mui/icons-material/Check';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import KeyboardArrowLeftIcon from '@mui/icons-material/KeyboardArrowLeft';
import KeyboardArrowRightIcon from '@mui/icons-material/KeyboardArrowRight';
import AccessTimeIcon from '@mui/icons-material/AccessTime';
import AccountTreeIcon from '@mui/icons-material/AccountTree';
import CodeIcon from '@mui/icons-material/Code';
import InboxIcon from '@mui/icons-material/Inbox';
import VisibilityIcon from '@mui/icons-material/Visibility';
import AutorenewIcon from '@mui/icons-material/Autorenew';
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

// ─── Guide steps ─────────────────────────────────────────────────────────────
const GUIDE_STEPS = [
    {
        icon: <InboxIcon sx={{ fontSize: 36, color: '#EB342E' }} />,
        label: 'What is the DLQ?',
        title: 'Dead-Letter Queue (DLQ)',
        color: '#EB342E',
        content: (
            <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary' }}>
                The <strong>Dead-Letter Queue</strong> is a holding area for jobs that could <em>not</em> be processed
                even after multiple automatic retries.
                <br /><br />
                Every transaction submitted via the API is processed by a background job. If the job fails 3 times
                in a row (e.g. due to a database error or network issue), it is moved here instead of being
                silently discarded — so <strong>no transaction is ever lost</strong>.
                <br /><br />
                As an admin, you can inspect the failure reason, retry the job, or permanently remove it.
            </Typography>
        ),
    },
    {
        icon: <AccessTimeIcon sx={{ fontSize: 36, color: '#f59e0b' }} />,
        label: 'Age Column',
        title: 'How long has it been failing?',
        color: '#f59e0b',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    The <strong>Age</strong> column shows how long ago the job failed. The colour indicates urgency:
                </Typography>
                <Stack spacing={1.5}>
                    {[
                        { color: 'success', label: 'Green — Less than 30 minutes', desc: 'Recent failure. Likely a transient issue; retry is safe.' },
                        { color: 'warning', label: 'Yellow — 30 min to 2 hours', desc: 'Moderate delay. Investigate before retrying.' },
                        { color: 'error',   label: 'Red — Over 2 hours', desc: 'Aged failure. Needs immediate attention or manual resolution.' },
                    ].map(({ color, label, desc }) => (
                        <Stack key={label} direction="row" spacing={1.5} alignItems="flex-start">
                            <Chip label="●" color={color} size="small" sx={{ height: 20, fontSize: '0.7rem', fontWeight: 900, mt: 0.2, flexShrink: 0, minWidth: 28 }} />
                            <Box>
                                <Typography variant="caption" sx={{ fontWeight: 800, display: 'block' }}>{label}</Typography>
                                <Typography variant="caption" sx={{ color: 'text.secondary' }}>{desc}</Typography>
                            </Box>
                        </Stack>
                    ))}
                </Stack>
            </Box>
        ),
    },
    {
        icon: <InboxIcon sx={{ fontSize: 36, color: '#3b82f6' }} />,
        label: 'Tenant Column',
        title: 'Which tenant is affected?',
        color: '#3b82f6',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    The <strong>Tenant</strong> column identifies which business entity the failed job belongs to.
                </Typography>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary' }}>
                    The system automatically extracts the <code>tenant_id</code> from the job payload and resolves its name.
                    <br /><br />
                    If the column says <strong>"System / Bulk"</strong>, the job is either a general background task (like report generation) or a bulk process that handles multiple tenants at once.
                </Typography>
            </Box>
        ),
    },
    {
        icon: <AccountTreeIcon sx={{ fontSize: 36, color: '#6366f1' }} />,
        label: 'Queue Column',
        title: 'Which processing queue failed?',
        color: '#6366f1',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    The <strong>Queue</strong> column shows which background queue the job was running on when it failed.
                </Typography>
                <Box sx={{ bgcolor: 'grey.50', borderRadius: 2, p: 2, border: '1px solid', borderColor: 'divider' }}>
                    {[
                        { q: 'transaction-processing:s0 – s7', desc: 'Sharded transaction validation queues (shard 0–7, allocated by tenant). Most common.' },
                        { q: 'transaction-processing', desc: 'Default processing queue if no shard was assigned.' },
                        { q: 'default', desc: 'General-purpose queue for non-transaction jobs.' },
                    ].map(({ q, desc }) => (
                        <Box key={q} sx={{ mb: 1.5 }}>
                            <Typography variant="caption" sx={{ fontFamily: 'monospace', fontWeight: 700, color: 'primary.main', display: 'block' }}>{q}</Typography>
                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>{desc}</Typography>
                        </Box>
                    ))}
                </Box>
                <Typography variant="caption" sx={{ color: 'text.secondary', mt: 1.5, display: 'block' }}>
                    💡 Multiple failures on the same shard may indicate a tenant-specific data issue.
                </Typography>
            </Box>
        ),
    },
    {
        icon: <CodeIcon sx={{ fontSize: 36, color: '#10b981' }} />,
        label: 'Job Class Column',
        title: 'What type of job failed?',
        color: '#10b981',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    The <strong>Job Class</strong> identifies which background task failed. This tells you the type of operation that was being processed.
                </Typography>
                <Box sx={{ bgcolor: 'grey.50', borderRadius: 2, p: 2, border: '1px solid', borderColor: 'divider' }}>
                    {[
                        { cls: 'ProcessTransactionJob', desc: 'Validates and records an incoming POS transaction. Most common in DLQ.' },
                        { cls: 'ForwardTransactionsToWebAppJob', desc: 'Forwards processed transactions to the web application layer.' },
                        { cls: 'RetryTransactionJob', desc: 'Handles automatic retry of a previously failed transaction.' },
                        { cls: 'CheckTransactionFailureThresholdsJob', desc: 'Evaluates whether failure rate triggers an alert notification.' },
                    ].map(({ cls, desc }) => (
                        <Box key={cls} sx={{ mb: 1.5 }}>
                            <Typography variant="caption" sx={{ fontFamily: 'monospace', fontWeight: 700, color: 'success.dark', display: 'block' }}>{cls}</Typography>
                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>{desc}</Typography>
                        </Box>
                    ))}
                </Box>
            </Box>
        ),
    },
    {
        icon: <ErrorOutlineIcon sx={{ fontSize: 36, color: '#ef4444' }} />,
        label: 'Error Preview',
        title: 'What went wrong?',
        color: '#ef4444',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    The <strong>Error Preview</strong> column shows the first line of the exception that caused the failure.
                    Hover over it for the full error message.
                </Typography>
                <Box sx={{ bgcolor: '#0b1120', borderRadius: 2, p: 1.5, mb: 2 }}>
                    <Typography sx={{ fontFamily: 'monospace', fontSize: '0.7rem', color: '#fca5a5', whiteSpace: 'pre-wrap' }}>
                        {`SQLSTATE[40001]: Serialization failure: 1213\nDeadlock found when trying to get lock;\ntry restarting transaction`}
                    </Typography>
                </Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary' }}>
                    <strong>Common error types:</strong>
                </Typography>
                <Stack spacing={0.5} sx={{ mt: 1 }}>
                    {[
                        { err: 'SQLSTATE[40001] Deadlock', action: '→ Safe to retry. Was already handled by the retry logic.' },
                        { err: 'Validation service returned unexpected result', action: '→ Check the transaction payload in the detail dialog.' },
                        { err: 'Connection refused / timeout', action: '→ Transient network issue. Retry after the service recovers.' },
                    ].map(({ err, action }) => (
                        <Box key={err}>
                            <Typography variant="caption" sx={{ fontWeight: 700, color: '#ef4444' }}>{err}</Typography>
                            <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block' }}>{action}</Typography>
                        </Box>
                    ))}
                </Stack>
            </Box>
        ),
    },
    {
        icon: <VisibilityIcon sx={{ fontSize: 36, color: '#0ea5e9' }} />,
        label: 'Detail Dialog',
        title: 'Inspecting a failed job',
        color: '#0ea5e9',
        content: (
            <Box>
                <Typography variant="body2" sx={{ lineHeight: 1.9, color: 'text.secondary', mb: 2 }}>
                    <strong>Click any row</strong> to open the detail dialog, which shows:
                </Typography>
                <Stack spacing={1.5}>
                    {[
                        { label: 'Full Exception Trace', desc: 'The complete stack trace of the error — useful for debugging the exact line of code that failed.' },
                        { label: 'Raw Job Payload', desc: 'The data the job was carrying when it failed (e.g. transaction ID, tenant, terminal).' },
                        { label: 'Copy Button', desc: 'Copies the full exception + payload to clipboard for sharing with the engineering team.' },
                        { label: 'Retry & Delete', desc: 'Directly retry or permanently remove the job from the detail view.' },
                    ].map(({ label, desc }) => (
                        <Stack key={label} direction="row" spacing={1.5} alignItems="flex-start">
                            <Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: '#0ea5e9', mt: 0.9, flexShrink: 0 }} />
                            <Box>
                                <Typography variant="caption" sx={{ fontWeight: 800, display: 'block' }}>{label}</Typography>
                                <Typography variant="caption" sx={{ color: 'text.secondary' }}>{desc}</Typography>
                            </Box>
                        </Stack>
                    ))}
                </Stack>
            </Box>
        ),
    },
    {
        icon: <AutorenewIcon sx={{ fontSize: 36, color: '#8b5cf6' }} />,
        label: 'Actions',
        title: 'Retry, Delete & Retry All',
        color: '#8b5cf6',
        content: (
            <Box>
                <Stack spacing={2}>
                    {[
                        {
                            icon: <ReplayIcon sx={{ color: 'primary.main', fontSize: 20 }} />,
                            label: 'Retry (single)',
                            desc: 'Re-queues one specific job. The job goes back into the processing queue and will be attempted again. Use this when you believe the failure was a temporary issue (e.g. short DB downtime).',
                            safe: true,
                        },
                        {
                            icon: <DeleteOutlineIcon sx={{ color: 'error.main', fontSize: 20 }} />,
                            label: 'Delete (flush)',
                            desc: 'Permanently removes the job from the DLQ. The transaction will NOT be retried. Only use this if the payload is invalid and should not be processed. This action is irreversible.',
                            safe: false,
                        },
                        {
                            icon: <ReplayIcon sx={{ color: 'warning.main', fontSize: 20 }} />,
                            label: 'Retry All',
                            desc: 'Re-queues every job in the DLQ at once. Use carefully — re-queuing a large number of jobs during peak hours may temporarily increase system load.',
                            safe: null,
                        },
                    ].map(({ icon, label, desc, safe }) => (
                        <Stack key={label} direction="row" spacing={1.5} alignItems="flex-start"
                            sx={{ p: 1.5, borderRadius: 2, bgcolor: 'grey.50', border: '1px solid', borderColor: 'divider' }}>
                            <Box sx={{ mt: 0.3 }}>{icon}</Box>
                            <Box sx={{ flexGrow: 1 }}>
                                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.3 }}>
                                    <Typography variant="caption" sx={{ fontWeight: 800 }}>{label}</Typography>
                                    {safe === true  && <Chip label="Safe" color="success" size="small" sx={{ height: 16, fontSize: '0.55rem', fontWeight: 800 }} />}
                                    {safe === false && <Chip label="Irreversible" color="error"   size="small" sx={{ height: 16, fontSize: '0.55rem', fontWeight: 800 }} />}
                                    {safe === null  && <Chip label="Use with caution" color="warning" size="small" sx={{ height: 16, fontSize: '0.55rem', fontWeight: 800 }} />}
                                </Stack>
                                <Typography variant="caption" sx={{ color: 'text.secondary', lineHeight: 1.7 }}>{desc}</Typography>
                            </Box>
                        </Stack>
                    ))}
                </Stack>
            </Box>
        ),
    },
];
// ─────────────────────────────────────────────────────────────────────────────

const DlqGuideDialog = ({ open, onClose }) => {
    const [step, setStep] = useState(0);
    const current = GUIDE_STEPS[step];
    const total = GUIDE_STEPS.length;

    const handleClose = () => { setStep(0); onClose(); };

    return (
        <Dialog open={open} onClose={handleClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 4, overflow: 'hidden' } }}>
            {/* Progress bar */}
            <LinearProgress
                variant="determinate"
                value={((step + 1) / total) * 100}
                sx={{ height: 3, bgcolor: 'grey.100', '& .MuiLinearProgress-bar': { bgcolor: current.color, transition: 'all 0.3s' } }}
            />

            <DialogTitle sx={{ px: 3, pt: 3, pb: 1.5 }}>
                <Stack direction="row" spacing={2} alignItems="center">
                    <Box sx={{ p: 1.2, bgcolor: `${current.color}15`, borderRadius: 2.5, display: 'flex', transition: 'all 0.3s' }}>
                        {current.icon}
                    </Box>
                    <Box sx={{ flexGrow: 1 }}>
                        <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'text.disabled', fontSize: '0.62rem' }}>
                            Step {step + 1} of {total} · {current.label}
                        </Typography>
                        <Typography variant="h6" sx={{ fontWeight: 900, lineHeight: 1.3, mt: 0.3 }}>
                            {current.title}
                        </Typography>
                    </Box>
                </Stack>

                {/* Step dots */}
                <Stack direction="row" spacing={0.5} sx={{ mt: 2 }}>
                    {GUIDE_STEPS.map((s, i) => (
                        <Box
                            key={i}
                            onClick={() => setStep(i)}
                            sx={{
                                width: i === step ? 24 : 8, height: 8,
                                borderRadius: 4,
                                bgcolor: i === step ? current.color : i < step ? `${current.color}40` : 'grey.200',
                                cursor: 'pointer',
                                transition: 'all 0.25s',
                            }}
                        />
                    ))}
                </Stack>
            </DialogTitle>

            <DialogContent sx={{ px: 3, pb: 1, minHeight: 240 }}>
                <Divider sx={{ mb: 2.5 }} />
                {current.content}
            </DialogContent>

            <DialogActions sx={{ px: 3, py: 2.5, bgcolor: 'grey.50', borderTop: '1px solid', borderColor: 'divider' }}>
                <Button
                    size="small"
                    startIcon={<KeyboardArrowLeftIcon />}
                    disabled={step === 0}
                    onClick={() => setStep(s => s - 1)}
                    sx={{ textTransform: 'none', fontWeight: 700 }}
                >
                    Previous
                </Button>
                <Box sx={{ flexGrow: 1 }} />
                {step < total - 1 ? (
                    <Button
                        variant="contained"
                        size="small"
                        endIcon={<KeyboardArrowRightIcon />}
                        onClick={() => setStep(s => s + 1)}
                        sx={{ textTransform: 'none', fontWeight: 700, bgcolor: current.color, '&:hover': { bgcolor: current.color, opacity: 0.9 } }}
                    >
                        Next
                    </Button>
                ) : (
                    <Button
                        variant="contained"
                        size="small"
                        startIcon={<CheckIcon />}
                        onClick={handleClose}
                        sx={{ textTransform: 'none', fontWeight: 700, bgcolor: '#10b981', '&:hover': { bgcolor: '#059669' } }}
                    >
                        Got it!
                    </Button>
                )}
            </DialogActions>
        </Dialog>
    );
};

// ─── Main component ───────────────────────────────────────────────────────────
const FailedJobsTable = ({ data, loading, onPageChange, onRefresh }) => {
    const [detailJob, setDetailJob]       = useState(null);
    const [copied, setCopied]             = useState(false);
    const [actionLoading, setActionLoading] = useState(null);
    const [actionError, setActionError]   = useState(null);
    const [actionSuccess, setActionSuccess] = useState(null);
    const [guideOpen, setGuideOpen]       = useState(false);

    const items = data?.data || [];
    const meta  = data?.meta  || {};
    const total = meta.total  || 0;
    const from  = total > 0 && meta.current_page && meta.per_page
        ? (meta.current_page - 1) * meta.per_page + 1 : 0;
    const to = from > 0 ? from + items.length - 1 : 0;

    const handleRetry = async (uuid, e) => {
        e?.stopPropagation();
        setActionLoading(uuid);
        setActionError(null); setActionSuccess(null);
        try {
            await dlqService.retry(uuid);
            setActionSuccess(`Job ${uuid.slice(0, 8)}… queued for retry.`);
            onRefresh?.();
        } catch (err) {
            setActionError(err?.response?.data?.message || 'Retry failed.');
        } finally { setActionLoading(null); }
    };

    const handleFlush = async (uuid, e) => {
        e?.stopPropagation();
        if (!window.confirm('Permanently delete this failed job? This cannot be undone.')) return;
        setActionLoading(uuid); setActionError(null);
        try {
            await dlqService.flush(uuid);
            setActionSuccess('Job deleted from DLQ.');
            onRefresh?.();
        } catch (err) {
            setActionError(err?.response?.data?.message || 'Delete failed.');
        } finally { setActionLoading(null); }
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
        } finally { setActionLoading(null); }
    };

    const handleCopy = () => {
        if (!detailJob) return;
        navigator.clipboard.writeText(
            JSON.stringify({ exception: detailJob.exception, payload: detailJob.payload }, null, 2)
        ).then(() => { setCopied(true); setTimeout(() => setCopied(false), 2000); });
    };

    const ageColor = (m) => m < 30 ? 'success' : m < 120 ? 'warning' : 'error';

    /** Convert total minutes → readable string like "2h 15m" or "3d 4h" */
    const formatAge = (minutes) => {
        if (minutes < 1)   return 'Just now';
        if (minutes < 60)  return `${minutes}m`;
        if (minutes < 1440) {
            const h = Math.floor(minutes / 60);
            const m = minutes % 60;
            return m > 0 ? `${h}h ${m}m` : `${h}h`;
        }
        const d = Math.floor(minutes / 1440);
        const h = Math.floor((minutes % 1440) / 60);
        return h > 0 ? `${d}d ${h}h` : `${d}d`;
    };

    /** Format the raw failed_at string to a short local datetime */
    const formatFailedAt = (ts) => {
        if (!ts) return '—';
        try {
            return new Date(ts).toLocaleString('en-PH', {
                month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: true,
            });
        } catch { return ts; }
    };

    if (loading && !data) {
        return (
            <Box sx={{ py: 20, textAlign: 'center' }}>
                <CircularProgress size={24} />
                <Typography variant="body2" sx={{ mt: 2, fontWeight: 700, color: 'text.secondary' }}>Loading failed jobs…</Typography>
            </Box>
        );
    }

    return (
        <>
            <DlqGuideDialog open={guideOpen} onClose={() => setGuideOpen(false)} />

            <Paper elevation={0} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 4, overflow: 'hidden' }}>

                {(actionError || actionSuccess) && (
                    <Box sx={{ p: 2, pb: 0 }}>
                        {actionError   && <Alert severity="error"   onClose={() => setActionError(null)}   sx={{ mb: 1 }}>{actionError}</Alert>}
                        {actionSuccess && <Alert severity="success" onClose={() => setActionSuccess(null)} sx={{ mb: 1 }}>{actionSuccess}</Alert>}
                    </Box>
                )}

                {/* Toolbar */}
                <Box sx={{ px: 2, pt: 2, pb: 1, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    {/* Guide button — always visible */}
                    <Tooltip title="Open interactive guide to understand this tab">
                        <Button
                            size="small"
                            startIcon={<HelpOutlineIcon />}
                            variant="outlined"
                            color="inherit"
                            onClick={() => setGuideOpen(true)}
                            sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem', color: 'text.secondary', borderColor: 'divider' }}
                        >
                            Guide
                        </Button>
                    </Tooltip>

                    {total > 0 && (
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
                    )}
                </Box>

                <TableContainer>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell sx={headerStyles}>Failed At</TableCell>
                                <TableCell sx={headerStyles}>Age</TableCell>
                                <TableCell sx={headerStyles}>Tenant</TableCell>
                                <TableCell sx={headerStyles}>Queue</TableCell>
                                <TableCell sx={headerStyles}>Job Class</TableCell>
                                <TableCell sx={headerStyles}>Error Preview</TableCell>
                                <TableCell sx={headerStyles} align="right">Actions</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {items.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} align="center" sx={{ py: 10 }}>
                                        <Stack alignItems="center" spacing={1}>
                                            <CheckIcon sx={{ fontSize: 32, color: 'success.main', opacity: 0.6 }} />
                                            <Typography variant="body2" sx={{ fontWeight: 700, opacity: 0.5 }}>
                                                Dead-letter queue is empty — all jobs processed successfully.
                                            </Typography>
                                            <Button
                                                size="small"
                                                startIcon={<HelpOutlineIcon />}
                                                onClick={() => setGuideOpen(true)}
                                                sx={{ mt: 1, textTransform: 'none', fontWeight: 700, fontSize: '0.72rem', opacity: 0.6 }}
                                            >
                                                Learn about the DLQ
                                            </Button>
                                        </Stack>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                items.map((job) => (
                                    <TableRow key={job.uuid} hover onClick={() => setDetailJob(job)} sx={{ cursor: 'pointer' }}>
                                        <TableCell>
                                            <Typography variant="body2" sx={{ fontWeight: 600, fontFamily: 'monospace', fontSize: '0.72rem' }}>
                                                {job.failed_at}
                                            </Typography>
                                        </TableCell>
                                        <TableCell>
                                            <Stack spacing={0.4}>
                                                <Chip
                                                    label={formatAge(job.age_minutes)}
                                                    color={ageColor(job.age_minutes)}
                                                    size="small"
                                                    sx={{ fontWeight: 800, fontSize: '0.62rem', height: 20, borderRadius: 1.5, width: 'fit-content' }}
                                                />
                                                <Typography variant="caption" sx={{ fontSize: '0.65rem', color: 'text.disabled', fontFamily: 'monospace', lineHeight: 1.2 }}>
                                                    {formatFailedAt(job.failed_at)}
                                                </Typography>
                                            </Stack>
                                        </TableCell>
                                        <TableCell>
                                            <Typography variant="caption" sx={{ fontWeight: 700, color: job.tenant_name ? 'text.primary' : 'text.disabled', fontSize: '0.68rem' }}>
                                                {job.tenant_name || 'System / Bulk'}
                                            </Typography>
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
                                                        <IconButton size="small" color="primary" disabled={actionLoading === job.uuid} onClick={(e) => handleRetry(job.uuid, e)}>
                                                            {actionLoading === job.uuid ? <CircularProgress size={14} /> : <ReplayIcon fontSize="small" />}
                                                        </IconButton>
                                                    </span>
                                                </Tooltip>
                                                <Tooltip title="Delete from DLQ">
                                                    <span>
                                                        <IconButton size="small" color="error" disabled={!!actionLoading} onClick={(e) => handleFlush(job.uuid, e)}>
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
                        {total > 0 ? `Showing ${from}–${to} of ${total} failed job${total !== 1 ? 's' : ''}` : 'Dead-letter queue is clear'}
                    </Typography>
                    <Pagination count={meta.last_page || 1} page={meta.current_page || 1} onChange={(_, p) => onPageChange?.(p)} color="primary" size="small" />
                </Box>

                {/* ── Detail dialog ── */}
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
                                <Typography sx={{ fontWeight: 800, fontSize: '0.88rem', color: '#e2e8f0' }}>Failed Job Detail</Typography>
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
                        <Box sx={{ bgcolor: '#0b1120', color: '#e2e8f0', fontFamily: '"JetBrains Mono", monospace', fontSize: '0.72rem', lineHeight: 1.7, maxHeight: 500, overflow: 'auto', p: 2.5 }}>
                            <Typography variant="caption" sx={{ color: '#EB342E', fontWeight: 800, display: 'block', mb: 1 }}>EXCEPTION</Typography>
                            <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: '#fca5a5', marginBottom: 24 }}>
                                {detailJob?.exception || 'No exception recorded'}
                            </pre>
                            <Typography variant="caption" sx={{ color: '#93c5fd', fontWeight: 800, display: 'block', mb: 1 }}>PAYLOAD</Typography>
                            <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: '#e2e8f0' }}>
                                {detailJob ? JSON.stringify(detailJob.payload, null, 2) : ''}
                            </pre>
                        </Box>
                    </DialogContent>

                    <DialogActions sx={{ bgcolor: '#0d1527', borderTop: '1px solid rgba(255,255,255,0.08)', px: 2.5, py: 1.25 }}>
                        <Stack direction="row" spacing={1} sx={{ ml: 'auto' }}>
                            <Button startIcon={<ReplayIcon />} variant="outlined" size="small" disabled={!!actionLoading}
                                onClick={() => { handleRetry(detailJob.uuid); setDetailJob(null); }}
                                sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem', color: 'rgba(255,255,255,0.7)', borderColor: 'rgba(255,255,255,0.2)' }}>
                                Retry
                            </Button>
                            <Button startIcon={<DeleteOutlineIcon />} variant="outlined" color="error" size="small"
                                onClick={() => { handleFlush(detailJob.uuid); setDetailJob(null); }}
                                sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}>
                                Delete
                            </Button>
                            <Button onClick={() => setDetailJob(null)} variant="contained" size="small"
                                sx={{ textTransform: 'none', fontWeight: 700, fontSize: '0.75rem' }}>
                                Close
                            </Button>
                        </Stack>
                    </DialogActions>
                </Dialog>
            </Paper>
        </>
    );
};

export default FailedJobsTable;
