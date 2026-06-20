import React, { useMemo } from 'react';
import { Box, Typography, Stack, Chip, List, ListItem, ListItemIcon, ListItemText, Divider } from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import WarningIcon from '@mui/icons-material/Warning';
import ErrorIcon from '@mui/icons-material/Error';
import GppGoodIcon from '@mui/icons-material/GppGood';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
};

export default function ComplianceStatusCard({ metrics }) {
    const csmrReady       = metrics?.compliance?.csmr_ready ?? false;
    const birGenerated    = metrics?.compliance?.bir_export_generated ?? false;
    const taxPassed       = metrics?.compliance?.tax_validation_passed ?? false;
    const lastSync        = metrics?.sync_status?.last_sync ?? 'N/A';

    const csmrDueDate = useMemo(() => {
        const d = new Date();
        d.setMonth(d.getMonth() + 1);
        return `${d.toLocaleString('default', { month: 'long' })} 5`;
    }, []);

    const items = [
        {
            label: 'CMSR Report',
            sub: `Certified Monthly Sales Report — due ${csmrDueDate}`,
            ok: csmrReady,
            chipLabel: csmrReady ? 'Ready' : 'Pending Review',
            chipColor: csmrReady ? 'success' : 'warning',
            icon: csmrReady
                ? <CheckCircleIcon sx={{ color: 'success.main' }} />
                : <WarningIcon sx={{ color: 'warning.main' }} />,
        },
        {
            label: 'BIR Export',
            sub: `Bureau of Internal Revenue — last run: ${lastSync}`,
            ok: birGenerated,
            chipLabel: birGenerated ? 'Generated' : 'Pending',
            chipColor: birGenerated ? 'success' : 'warning',
            icon: birGenerated
                ? <CheckCircleIcon sx={{ color: 'success.main' }} />
                : <WarningIcon sx={{ color: 'warning.main' }} />,
        },
        {
            label: 'Tax Validation',
            sub: `Math rules verification — validated: ${lastSync}`,
            ok: taxPassed,
            chipLabel: taxPassed ? 'Passed' : 'Failed',
            chipColor: taxPassed ? 'success' : 'error',
            icon: taxPassed
                ? <CheckCircleIcon sx={{ color: 'success.main' }} />
                : <ErrorIcon sx={{ color: 'error.main' }} />,
        },
    ];

    const allGreen = items.every((i) => i.ok);

    return (
        <Box sx={GLASS}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Compliance Status
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        Submission readiness checklist
                    </Typography>
                </Box>
                <GppGoodIcon sx={{ color: allGreen ? 'success.main' : 'text.disabled', fontSize: 22 }} />
            </Stack>

            {/* Checklist */}
            <List disablePadding sx={{ flex: 1 }}>
                {items.map(({ label, sub, icon, chipLabel, chipColor }, i) => (
                    <React.Fragment key={label}>
                        <ListItem
                            disablePadding
                            sx={{ py: 1.75, alignItems: 'flex-start', gap: 1 }}
                        >
                            <ListItemIcon sx={{ minWidth: 36, mt: 0.25 }}>
                                {icon}
                            </ListItemIcon>
                            <ListItemText
                                primary={
                                    <Typography sx={{ fontWeight: 800, fontSize: '0.9rem', color: 'text.primary' }}>
                                        {label}
                                    </Typography>
                                }
                                secondary={
                                    <Typography variant="caption" sx={{ color: 'text.secondary', fontSize: '0.72rem' }}>
                                        {sub}
                                    </Typography>
                                }
                            />
                            <Chip
                                label={chipLabel}
                                color={chipColor}
                                size="small"
                                sx={{ fontWeight: 800, fontSize: '0.68rem', flexShrink: 0 }}
                            />
                        </ListItem>
                        {i < items.length - 1 && (
                            <Divider sx={{ opacity: 0.4 }} />
                        )}
                    </React.Fragment>
                ))}
            </List>
        </Box>
    );
}
