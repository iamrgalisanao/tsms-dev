import React from 'react';
import { Box, Typography, Stack, Button, Divider } from '@mui/material';
import GetAppIcon from '@mui/icons-material/GetApp';
import AssessmentIcon from '@mui/icons-material/Assessment';
import DownloadIcon from '@mui/icons-material/Download';

const CARD_STYLE = {
    p: 3,
    borderRadius: '10px',
    border: '1px solid #E8ECF4',
    boxShadow: '0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04)',
    bgcolor: '#FFFFFF',
    mb: 4,
};

const handleExport = (endpoint, params = {}) => {
    const query = new URLSearchParams(params).toString();
    window.open(`${endpoint}?${query}`, '_blank');
};

export default function QuickActionsHub() {
    const now = new Date();

    return (
        <Box sx={CARD_STYLE}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 700, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Quick Actions Hub
                    </Typography>
                    <Typography sx={{ fontWeight: 600, color: '#64748B', textTransform: 'uppercase', letterSpacing: '0.08em', fontSize: '11px' }}>
                        On-demand financial exports
                    </Typography>
                </Box>
                <GetAppIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            <Stack
                direction={{ xs: 'column', md: 'row' }}
                spacing={3}
                divider={<Divider orientation="vertical" flexItem sx={{ opacity: 0.3 }} />}
            >
                {/* Reports group */}
                <Box sx={{ flex: 1 }}>
                    <Typography sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.1em', color: '#64748B', fontSize: '11px', display: 'block', mb: 2 }}>
                        Reports
                    </Typography>
                    <Stack spacing={2}>
                        {/* Primary CTA (CSMR) */}
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
                                borderRadius: '10px',
                                fontWeight: 500,
                                fontSize: '14px',
                                textTransform: 'none',
                                bgcolor: '#1A56DB',
                                color: '#FFFFFF',
                                boxShadow: '0 2px 8px rgba(26,86,219,0.35)',
                                '&:hover': { bgcolor: '#1347B8', boxShadow: '0 4px 12px rgba(26,86,219,0.45)' },
                            }}
                        >
                            Generate CSMR Report
                        </Button>
                        {/* Primary CTA (Audit) */}
                        <Button
                            fullWidth
                            variant="contained"
                            size="large"
                            startIcon={<AssessmentIcon />}
                            onClick={() => handleExport('/api/dashboard/export-audit-logs')}
                            sx={{
                                borderRadius: '10px',
                                fontWeight: 500,
                                fontSize: '14px',
                                textTransform: 'none',
                                bgcolor: '#1A56DB',
                                color: '#FFFFFF',
                                boxShadow: '0 2px 8px rgba(26,86,219,0.35)',
                                '&:hover': { bgcolor: '#1347B8', boxShadow: '0 4px 12px rgba(26,86,219,0.45)' },
                            }}
                        >
                            Generate Audit Report
                        </Button>
                    </Stack>
                </Box>

                {/* Exports group */}
                <Box sx={{ flex: 1 }}>
                    <Typography sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.1em', color: '#64748B', fontSize: '11px', display: 'block', mb: 2 }}>
                        Exports
                    </Typography>
                    <Stack spacing={2}>
                        {/* Secondary CTA (Sales) */}
                        <Button
                            fullWidth
                            variant="outlined"
                            size="large"
                            startIcon={<DownloadIcon />}
                            onClick={() => handleExport('/api/dashboard/export-transactions')}
                            sx={{
                                borderRadius: '10px',
                                fontWeight: 500,
                                fontSize: '14px',
                                textTransform: 'none',
                                borderColor: '#E8ECF4',
                                color: '#0F172A',
                                bgcolor: '#FFFFFF',
                                '&:hover': {
                                    borderColor: '#1A56DB',
                                    bgcolor: '#EEF2FF',
                                    color: '#1A56DB'
                                }
                            }}
                        >
                            Export Sales Data
                        </Button>
                        {/* Secondary CTA (Reconciliation) */}
                        <Button
                            fullWidth
                            variant="outlined"
                            size="large"
                            startIcon={<DownloadIcon />}
                            onClick={() => handleExport('/logs/export/csv')}
                            sx={{
                                borderRadius: '10px',
                                fontWeight: 500,
                                fontSize: '14px',
                                textTransform: 'none',
                                borderColor: '#E8ECF4',
                                color: '#0F172A',
                                bgcolor: '#FFFFFF',
                                '&:hover': {
                                    borderColor: '#1A56DB',
                                    bgcolor: '#EEF2FF',
                                    color: '#1A56DB'
                                }
                            }}
                        >
                            Export Reconciliation
                        </Button>
                    </Stack>
                </Box>
            </Stack>
        </Box>
    );
}
