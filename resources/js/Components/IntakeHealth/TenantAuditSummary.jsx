import React from 'react';
import { Paper, Box, Typography, Stack } from '@mui/material';
import ReportProblemIcon from '@mui/icons-material/ReportProblem';
import TimelineIcon from '@mui/icons-material/Timeline';
import BlockIcon from '@mui/icons-material/Block';

const TenantAuditSummary = ({
    auditIssueCount = 0,
    driftCount = 0,
    noPersistedCount = 0
}) => {
    const metrics = [
        {
            label: 'Tenants With Flags',
            value: auditIssueCount,
            color: auditIssueCount > 0 ? '#feb700' : '#00e676',
            bgColor: auditIssueCount > 0 ? 'rgba(254, 183, 0, 0.1)' : 'rgba(76, 175, 80, 0.1)',
            icon: <ReportProblemIcon style={{ fontSize: 18 }} />
        },
        {
            label: 'Drift Detected',
            value: driftCount,
            color: driftCount > 0 ? '#ff1744' : '#00e676',
            bgColor: driftCount > 0 ? 'rgba(255, 23, 68, 0.1)' : 'rgba(76, 175, 80, 0.1)',
            icon: <TimelineIcon style={{ fontSize: 18 }} />
        },
        {
            label: 'Persist Failures',
            value: noPersistedCount,
            color: noPersistedCount > 0 ? '#ff1744' : '#00e676',
            bgColor: noPersistedCount > 0 ? 'rgba(255, 23, 68, 0.1)' : 'rgba(76, 175, 80, 0.1)',
            icon: <BlockIcon style={{ fontSize: 18 }} />
        }
    ];

    return (
        <Paper
            className="glass-container"
            sx={{
                p: 3,
                borderRadius: '20px',
                border: '1px solid',
                borderColor: 'divider',
                bgcolor: 'white',
                height: '100%'
            }}
        >
            <Stack spacing={2.5}>
                <div>
                    <Typography variant="subtitle1" sx={{ fontWeight: 1000, color: '#101221', mb: 0.5 }}>
                        LIVE AUDIT SUMMARY
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700 }}>
                        Identified operational anomalies
                    </Typography>
                </div>

                <Stack spacing={2}>
                    {metrics.map((m) => (
                        <Box
                            key={m.label}
                            sx={{
                                p: 2,
                                borderRadius: '14px',
                                border: '1px solid',
                                borderColor: 'divider',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                transition: 'all 0.2s',
                                '&:hover': {
                                    boxShadow: '0 4px 12px rgba(0,0,0,0.02)'
                                }
                            }}
                        >
                            <Stack direction="row" spacing={1.5} alignItems="center">
                                <Box
                                    sx={{
                                        p: 1,
                                        borderRadius: '8px',
                                        bgcolor: m.bgColor,
                                        color: m.color,
                                        display: 'flex'
                                    }}
                                >
                                    {m.icon}
                                </Box>
                                <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary', fontSize: '0.78rem' }}>
                                    {m.label}
                                </Typography>
                            </Stack>
                            <Typography
                                variant="h5"
                                sx={{
                                    fontWeight: 1000,
                                    color: m.value > 0 ? m.color : 'text.primary',
                                    fontFamily: 'monospace'
                                }}
                            >
                                {m.value}
                            </Typography>
                        </Box>
                    ))}
                </Stack>
            </Stack>
        </Paper>
    );
};

export default TenantAuditSummary;
