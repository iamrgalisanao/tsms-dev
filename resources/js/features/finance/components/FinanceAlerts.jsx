import React from 'react';
import { Box, Typography, Stack, Grid, Alert, Button } from '@mui/material';
import WarningIcon from '@mui/icons-material/Warning';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
    mb: 4,
};

export default function FinanceAlerts({ metrics }) {
    const failedRecon = metrics?.exceptions?.failed_reconciliations ?? 0;
    const missingUploads = metrics?.exceptions?.missing_uploads ?? 0;
    const pendingTxn = metrics?.reconciliation?.pending ?? 0;
    const csmrReady = metrics?.compliance?.csmr_ready ?? false;

    const alerts = [
        {
            key: 'recon',
            severity: failedRecon > 0 ? 'error' : 'success',
            label: failedRecon > 0
                ? `${failedRecon} Failed Reconciliation${failedRecon > 1 ? 's' : ''}`
                : 'No Failed Reconciliations',
            action: failedRecon > 0 ? () => { window.location.href = '/transactions?status=FAILED'; } : null,
            actionLabel: 'Review',
        },
        {
            key: 'upload',
            severity: missingUploads > 0 ? 'warning' : 'success',
            label: missingUploads > 0 ? `${missingUploads} Missing Uploads` : 'Upload Ingestion Synced',
            action: null,
        },
        {
            key: 'pending',
            severity: pendingTxn > 0 ? 'warning' : 'success',
            label: pendingTxn > 0 ? `${pendingTxn} Unprocessed Transactions` : 'All Transactions Processed',
            action: null,
        },
        {
            key: 'csmr',
            severity: csmrReady ? 'success' : 'info',
            label: csmrReady ? 'CSMR Reports Ready' : 'CSMR Reports Processing',
            action: !csmrReady ? () => { window.location.href = '/reports'; } : null,
            actionLabel: 'View Reports',
        },
    ];

    return (
        <Box sx={GLASS}>
            <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mb: 3 }}>
                <WarningIcon sx={{ color: 'warning.main', fontSize: 20 }} />
                <Typography variant="body2" sx={{ fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                    Finance Alerts
                </Typography>
            </Stack>

            <Grid container spacing={2}>
                {alerts.map(({ key, severity, label, action, actionLabel }) => (
                    <Grid item xs={12} sm={6} lg={3} key={key}>
                        <Alert
                            severity={severity}
                            icon={severity === 'success' ? <CheckCircleIcon fontSize="inherit" /> : undefined}
                            sx={{
                                borderRadius: '14px',
                                fontWeight: 700,
                                fontSize: '0.8rem',
                                cursor: action ? 'pointer' : 'default',
                                transition: action ? 'opacity 0.15s' : 'none',
                                '&:hover': action ? { opacity: 0.88 } : {},
                            }}
                            onClick={action || undefined}
                            action={action && (
                                <Button
                                    color="inherit"
                                    size="small"
                                    onClick={(e) => { e.stopPropagation(); action(); }}
                                    sx={{ fontWeight: 800, fontSize: '0.7rem', textTransform: 'uppercase' }}
                                >
                                    {actionLabel}
                                </Button>
                            )}
                        >
                            {label}
                        </Alert>
                    </Grid>
                ))}
            </Grid>
        </Box>
    );
}
