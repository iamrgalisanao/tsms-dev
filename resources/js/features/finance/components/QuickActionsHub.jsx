import React from 'react';
import { Box, Typography, Stack, Button, Divider } from '@mui/material';
import GetAppIcon from '@mui/icons-material/GetApp';
import AssessmentIcon from '@mui/icons-material/Assessment';
import DownloadIcon from '@mui/icons-material/Download';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
    mb: 4,
};

const handleExport = (endpoint, params = {}) => {
    const query = new URLSearchParams(params).toString();
    window.open(`${endpoint}?${query}`, '_blank');
};

export default function QuickActionsHub() {
    const now = new Date();

    return (
        <Box sx={GLASS}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Quick Actions Hub
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        On-demand financial exports
                    </Typography>
                </Box>
                <GetAppIcon sx={{ color: 'text.disabled', fontSize: 20 }} />
            </Stack>

            <Stack
                direction={{ xs: 'column', md: 'row' }}
                spacing={3}
                divider={<Divider orientation="vertical" flexItem sx={{ opacity: 0.3 }} />}
            >
                {/* Reports group */}
                <Box sx={{ flex: 1 }}>
                    <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'text.disabled', fontSize: '0.65rem', display: 'block', mb: 2 }}>
                        Reports
                    </Typography>
                    <Stack spacing={2}>
                        {/* Primary CTA */}
                        <Button
                            fullWidth
                            variant="contained"
                            size="large"
                            startIcon={<AssessmentIcon />}
                            onClick={() => handleExport('/finance/reports/export', {
                                year: now.getFullYear(),
                                month: now.getMonth() + 1,
                            })}
                            sx={{
                                borderRadius: '14px',
                                fontWeight: 900,
                                bgcolor: 'primary.main',
                                boxShadow: '0 6px 20px rgba(29,67,155,0.2)',
                                '&:hover': { bgcolor: 'primary.dark' },
                            }}
                        >
                            Generate CSMR Report
                        </Button>
                        <Button
                            fullWidth
                            variant="outlined"
                            startIcon={<AssessmentIcon />}
                            onClick={() => handleExport('/api/dashboard/export-audit-logs')}
                            sx={{ borderRadius: '14px', fontWeight: 800, borderStyle: 'dashed' }}
                        >
                            Generate Audit Report
                        </Button>
                    </Stack>
                </Box>

                {/* Exports group */}
                <Box sx={{ flex: 1 }}>
                    <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'text.disabled', fontSize: '0.65rem', display: 'block', mb: 2 }}>
                        Exports
                    </Typography>
                    <Stack spacing={2}>
                        <Button
                            fullWidth
                            variant="outlined"
                            startIcon={<DownloadIcon />}
                            onClick={() => handleExport('/api/dashboard/export-transactions')}
                            sx={{ borderRadius: '14px', fontWeight: 800, borderStyle: 'dashed' }}
                        >
                            Export Sales Data
                        </Button>
                        <Button
                            fullWidth
                            variant="outlined"
                            startIcon={<DownloadIcon />}
                            onClick={() => handleExport('/logs/export/csv')}
                            sx={{ borderRadius: '14px', fontWeight: 800, borderStyle: 'dashed' }}
                        >
                            Export Reconciliation
                        </Button>
                    </Stack>
                </Box>
            </Stack>
        </Box>
    );
}
