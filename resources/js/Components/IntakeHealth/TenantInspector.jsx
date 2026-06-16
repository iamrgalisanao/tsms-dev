import React from 'react';
import { Paper, Box, Typography, Stack, Chip, Grid, Button, Divider } from '@mui/material';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import RefreshIcon from '@mui/icons-material/Refresh';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import LibraryBooksIcon from '@mui/icons-material/LibraryBooks';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';

const TenantInspector = ({
    tenant = null,
    onReplay,
    onInspect,
    onViewLogs
}) => {
    if (!tenant) {
        return (
            <Paper
                className="glass-container"
                sx={{
                    p: 4,
                    height: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    alignItems: 'center',
                    borderRadius: '20px',
                    border: '1px solid',
                    borderColor: 'divider',
                    bgcolor: 'white',
                    textAlign: 'center',
                    minHeight: '400px'
                }}
            >
                <Box sx={{ p: 2, borderRadius: '50%', bgcolor: 'rgba(0,242,255,0.05)', color: '#00f2ff', mb: 2.5 }}>
                    <FactCheckIcon sx={{ fontSize: 40 }} />
                </Box>
                <Typography variant="subtitle1" sx={{ fontWeight: 1000, color: '#101221', mb: 1, letterSpacing: '0.02em' }}>
                    Select Tenant to Inspect
                </Typography>
                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, maxWidth: '280px', lineHeight: 1.4 }}>
                    Click any row in the audit table to view detailed tenant configuration, historical activity, and context-specific troubleshooting workflows.
                </Typography>
            </Paper>
        );
    }

    const flags = tenant.flags || [];
    const hasIssues = flags.length > 0;
    
    // Troubleshooting guide based on flags
    const getTroubleshootingTips = (flags) => {
        const tips = [];
        if (flags.includes('TENANT_TERMINAL_DRIFT')) {
            tips.push({
                title: 'Terminal Hardware ID Mismatch',
                desc: 'Active terminals reported by provider do not match our registered records. Verify device access keys.'
            });
        }
        if (flags.includes('NO_PERSISTED_TX_WITH_ACTIVITY')) {
            tips.push({
                title: 'Zero Transactions Persisted',
                desc: 'Intake logs confirm batch submissions but database transaction write-backs are zero. Replay queue to force ingestion.'
            });
        }
        if (flags.includes('QUARANTINED_SUBMISSIONS')) {
            tips.push({
                title: 'Validation Schema Failure',
                desc: 'Payloads are failing hardware verification or amount checksums. Check sandbox payload inspector.'
            });
        }
        if (tips.length === 0) {
            tips.push({
                title: 'All Ingestion Checks Passed',
                desc: 'No anomalies detected. Ingestion pipeline is synced, access tokens are active, and sales drift is at 0.00%.'
            });
        }
        return tips;
    };

    const tips = getTroubleshootingTips(flags);

    // Mock recent activity log for the specific selected tenant
    const mockTimeline = [
        { time: '3m ago', msg: 'Sales summary checksum validated', status: 'success' },
        { time: '14m ago', msg: `Batch of ${tenant.submissions || 5} records processed`, status: 'success' },
        { time: '28m ago', msg: 'Access token handshake completed', status: 'success' },
        ...(hasIssues ? [{ time: '1h ago', msg: `Drift trigger detected: ${flags.join(', ')}`, status: 'warning' }] : [])
    ];

    return (
        <Paper
            className="glass-container"
            sx={{
                p: 3,
                borderRadius: '20px',
                border: '1.5px solid',
                borderColor: hasIssues ? 'warning.main' : 'divider',
                bgcolor: 'white',
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
                boxShadow: hasIssues ? '0 10px 30px rgba(255, 152, 0, 0.05)' : 'none'
            }}
        >
            {/* Header info */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography variant="h6" sx={{ fontWeight: 1000, color: '#101221', letterSpacing: '-0.02em', mb: 0.5 }}>
                        {tenant.tenant}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, letterSpacing: '0.03em' }}>
                        ID: #{tenant.tenant_id} · STATUS: {tenant.status || 'ACTIVE'}
                    </Typography>
                </Box>
                <Chip 
                    label={hasIssues ? `${flags.length} FLAGS` : "HEALTHY"} 
                    color={hasIssues ? "warning" : "success"}
                    size="small"
                    sx={{ fontWeight: 900, borderRadius: '6px' }}
                />
            </Stack>

            {/* Quick Metrics Grid */}
            <Typography variant="caption" sx={{ display: 'block', fontWeight: 900, color: 'text.secondary', letterSpacing: '0.08em', mb: 1, textTransform: 'uppercase' }}>
                Tenant Core Metrics
            </Typography>
            <Grid container spacing={1.5} sx={{ mb: 3 }}>
                {[
                    { label: 'Gross Sales', val: `₱${Number(tenant.gross_sales || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}` },
                    { label: 'Terminals', val: `${tenant.active_terminals}/${tenant.terminals}` },
                    { label: 'Submissions', val: tenant.submissions },
                    { label: 'Valid / Pending', val: `${tenant.valid} / ${tenant.pending}` },
                    { label: 'Quarantined', val: tenant.quarantined, color: tenant.quarantined > 0 ? '#ff1744' : 'inherit' },
                    { label: 'Failed Ingests', val: tenant.invalid_or_failed, color: tenant.invalid_or_failed > 0 ? '#ff1744' : 'inherit' }
                ].map((m) => (
                    <Grid item xs={6} key={m.label}>
                        <Box sx={{ p: 1.5, border: '1px solid', borderColor: 'divider', borderRadius: '12px', bgcolor: 'rgba(0,0,0,0.01)' }}>
                            <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, fontSize: '0.62rem', display: 'block', mb: 0.2 }}>
                                {m.label}
                            </Typography>
                            <Typography variant="subtitle2" sx={{ fontWeight: 900, color: m.color || '#101221', fontSize: '0.85rem' }}>
                                {m.val}
                            </Typography>
                        </Box>
                    </Grid>
                ))}
            </Grid>

            <Divider sx={{ mb: 2.5 }} />

            {/* Ingestion Issue Diagnostics / Recommendations */}
            <Typography variant="caption" sx={{ display: 'block', fontWeight: 900, color: 'text.secondary', letterSpacing: '0.08em', mb: 1.5, textTransform: 'uppercase' }}>
                Operational Diagnostics
            </Typography>
            <Stack spacing={1.5} sx={{ mb: 3 }}>
                {tips.map((tip, idx) => (
                    <Box key={idx} sx={{ p: 2, borderRadius: '12px', bgcolor: hasIssues ? 'rgba(255, 152, 0, 0.04)' : 'rgba(76, 175, 80, 0.04)', borderLeft: '4px solid', borderLeftColor: hasIssues ? 'warning.main' : 'success.main' }}>
                        <Typography variant="caption" sx={{ fontWeight: 900, display: 'flex', alignItems: 'center', color: '#101221', mb: 0.5 }}>
                            <HelpOutlineIcon sx={{ mr: 1, fontSize: 14 }} /> {tip.title}
                        </Typography>
                        <Typography variant="caption" sx={{ color: 'text.secondary', leading: '1.4', fontWeight: 700, display: 'block' }}>
                            {tip.desc}
                        </Typography>
                    </Box>
                ))}
            </Stack>

            <Divider sx={{ mb: 2.5 }} />

            {/* Selected Tenant Recent Events Timeline */}
            <Typography variant="caption" sx={{ display: 'block', fontWeight: 900, color: 'text.secondary', letterSpacing: '0.08em', mb: 1.5, textTransform: 'uppercase' }}>
                Tenant Ingestion Activity
            </Typography>
            <Stack spacing={1.5} sx={{ mb: 3.5, flexGrow: 1 }}>
                {mockTimeline.map((item, idx) => (
                    <Stack direction="row" spacing={1.5} alignItems="flex-start" key={idx}>
                        <Typography variant="caption" sx={{ color: 'text.disabled', fontFamily: 'monospace', fontWeight: 800, pt: 0.2, fontSize: '0.62rem', minWidth: '45px' }}>
                            {item.time}
                        </Typography>
                        <Box sx={{ mt: 0.8, width: 6, height: 6, borderRadius: '50%', bgcolor: item.status === 'success' ? 'success.main' : 'warning.main' }} />
                        <Typography variant="caption" sx={{ color: 'text.primary', fontWeight: 700, fontSize: '0.7rem' }}>
                            {item.msg}
                        </Typography>
                    </Stack>
                ))}
            </Stack>

            {/* Operational Context Actions */}
            <Stack spacing={1}>
                <Button
                    fullWidth
                    variant="contained"
                    onClick={() => onReplay && onReplay(tenant)}
                    startIcon={<RefreshIcon />}
                    sx={{
                        height: '38px',
                        borderRadius: '10px',
                        fontWeight: 900,
                        textTransform: 'none',
                        fontSize: '0.78rem',
                        bgcolor: '#101221',
                        color: 'white',
                        '&:hover': { bgcolor: '#1d1e2e' }
                    }}
                >
                    Replay Pending Queue
                </Button>
                <Stack direction="row" spacing={1}>
                    <Button
                        fullWidth
                        variant="outlined"
                        onClick={() => onInspect && onInspect(tenant)}
                        startIcon={<OpenInNewIcon sx={{ fontSize: 12 }} />}
                        sx={{
                            height: '36px',
                            borderRadius: '10px',
                            fontWeight: 900,
                            textTransform: 'none',
                            fontSize: '0.75rem'
                        }}
                    >
                        Drift Inspector
                    </Button>
                    <Button
                        fullWidth
                        variant="outlined"
                        onClick={() => onViewLogs && onViewLogs(tenant)}
                        startIcon={<LibraryBooksIcon sx={{ fontSize: 12 }} />}
                        sx={{
                            height: '36px',
                            borderRadius: '10px',
                            fontWeight: 900,
                            textTransform: 'none',
                            fontSize: '0.75rem'
                        }}
                    >
                        Ingest Logs
                    </Button>
                </Stack>
            </Stack>
        </Paper>
    );
};

export default TenantInspector;
