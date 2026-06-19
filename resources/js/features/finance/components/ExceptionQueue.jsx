import React from 'react';
import { Box, Typography, Stack, Grid } from '@mui/material';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import ExceptionCard from './ExceptionCard';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
    mb: 4,
};

export default function ExceptionQueue({ metrics }) {
    const failedRecon   = metrics?.exceptions?.failed_reconciliations ?? 0;
    const missingUploads = metrics?.exceptions?.missing_uploads ?? 0;
    const invalidTax    = metrics?.exceptions?.invalid_tax_records ?? 0;

    return (
        <Box sx={GLASS}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Exception Queue
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        Unresolved operational and tax discrepancies
                    </Typography>
                </Box>
                <ErrorOutlineIcon sx={{ color: failedRecon > 0 ? 'error.main' : 'text.disabled', fontSize: 22 }} />
            </Stack>

            <Grid container spacing={3}>
                <Grid item xs={12} md={4}>
                    <ExceptionCard
                        title="Failed Reconciliation"
                        value={failedRecon}
                        action={failedRecon > 0 ? 'Needs review' : 'All clear'}
                        severity={failedRecon > 0 ? 'error' : 'success'}
                        onClick={failedRecon > 0 ? () => { window.location.href = '/transactions?status=FAILED'; } : undefined}
                    />
                </Grid>
                <Grid item xs={12} md={4}>
                    <ExceptionCard
                        title="Missing Terminal Uploads"
                        value={missingUploads}
                        action={missingUploads > 0 ? 'Check logs' : 'No missing uploads'}
                        severity={missingUploads > 0 ? 'warning' : 'success'}
                        onClick={missingUploads > 0 ? () => { window.location.href = '/system-logs'; } : undefined}
                    />
                </Grid>
                <Grid item xs={12} md={4}>
                    <ExceptionCard
                        title="Invalid Tax Records"
                        value={invalidTax}
                        action={invalidTax > 0 ? 'Audit maths' : 'Tax validation clean'}
                        severity={invalidTax > 0 ? 'error' : 'success'}
                        onClick={invalidTax > 0 ? () => { window.location.href = '/transactions?status=FAILED'; } : undefined}
                    />
                </Grid>
            </Grid>
        </Box>
    );
}
