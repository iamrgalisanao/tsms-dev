import React from 'react';
import { Box, Typography, Stack, Button } from '@mui/material';
import WarningIcon from '@mui/icons-material/Warning';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorIcon from '@mui/icons-material/Error';

const CARD_STYLE = {
    p: 3,
    borderRadius: '10px',
    border: '1px solid #E8ECF4',
    boxShadow: '0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04)',
    bgcolor: '#FFFFFF',
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

    const activeCount = alerts.filter(a => a.severity !== 'success').length;

    return (
        <Box sx={CARD_STYLE}>
            {/* Header with active status badge */}
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2.5 }}>
                <Stack direction="row" alignItems="center" spacing={1}>
                    <WarningIcon sx={{ color: '#D97706', fontSize: 18 }} />
                    <Typography sx={{ fontWeight: 700, fontSize: '13px', textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748B' }}>
                        Finance Alerts
                    </Typography>
                </Stack>
                <Box sx={{
                    bgcolor: activeCount > 0 ? '#FEF3C7' : '#DCFCE7',
                    color: activeCount > 0 ? '#D97706' : '#16A34A',
                    px: 1.5, py: 0.5,
                    borderRadius: '20px',
                    fontSize: '11px',
                    fontWeight: 600,
                    textTransform: 'uppercase',
                    letterSpacing: '0.05em'
                }}>
                    {activeCount > 0 ? `${activeCount} active` : 'All Clear'}
                </Box>
            </Stack>

            {/* List of alert items */}
            <Stack spacing={1}>
                {alerts.map(({ key, severity, label, action, actionLabel }) => {
                    const isCritical = severity === 'error';
                    const isWarning = severity === 'warning';
                    const isInfo = severity === 'info';
                    
                    let iconColor = '#16A34A';
                    let alertIcon = <CheckCircleIcon sx={{ fontSize: 16 }} />;
                    if (isCritical) {
                        iconColor = '#DC2626';
                        alertIcon = <ErrorIcon sx={{ fontSize: 16 }} />;
                    } else if (isWarning) {
                        iconColor = '#D97706';
                        alertIcon = <WarningIcon sx={{ fontSize: 16 }} />;
                    } else if (isInfo) {
                        iconColor = '#1A56DB';
                        alertIcon = <WarningIcon sx={{ fontSize: 16 }} />;
                    }

                    return (
                        <Stack
                            key={key}
                            direction="row"
                            alignItems="center"
                            justifyContent="space-between"
                            onClick={action || undefined}
                            sx={{
                                p: '8px 12px',
                                minHeight: '40px',
                                borderRadius: '6px',
                                transition: 'background-color 150ms ease',
                                cursor: action ? 'pointer' : 'default',
                                borderLeft: isCritical ? '3px solid #DC2626' : '3px solid transparent',
                                bgcolor: isCritical ? 'rgba(220,38,38,0.02)' : 'transparent',
                                '&:hover': {
                                    bgcolor: isCritical ? 'rgba(220,38,38,0.04)' : '#F8FAFC'
                                }
                            }}
                        >
                            <Stack direction="row" alignItems="center" spacing={1.5}>
                                <Box sx={{ color: iconColor, display: 'flex', alignItems: 'center' }}>
                                    {alertIcon}
                                </Box>
                                <Typography sx={{ fontSize: '14px', fontWeight: 500, color: '#0F172A' }}>
                                    {label}
                                </Typography>
                            </Stack>
                            
                            {action && (
                                <Button
                                    size="small"
                                    onClick={(e) => { e.stopPropagation(); action(); }}
                                    sx={{
                                        fontWeight: 600,
                                        fontSize: '12px',
                                        textTransform: 'none',
                                        color: '#1A56DB',
                                        bgcolor: '#EEF2FF',
                                        px: 1.5, py: 0.5,
                                        borderRadius: '6px',
                                        '&:hover': { bgcolor: '#E0E7FF' }
                                    }}
                                >
                                    {actionLabel}
                                </Button>
                            )}
                        </Stack>
                    );
                })}
            </Stack>
        </Box>
    );
}
