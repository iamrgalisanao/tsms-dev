import React from 'react';
import { Box, Typography, Stack } from '@mui/material';
import GetAppIcon from '@mui/icons-material/GetApp';
import AssessmentIcon from '@mui/icons-material/Assessment';
import DownloadIcon from '@mui/icons-material/Download';

const CARD_STYLE = {
    p: 2.5,
    borderRadius: '12px',
    border: '1px solid #E2E8F0',
    boxShadow: '0 10px 24px rgba(15,23,42,0.045), 0 1px 2px rgba(15,23,42,0.06)',
    bgcolor: '#FFFFFF',
    height: '100%',
};

const handleExport = (endpoint, params = {}) => {
    const query = new URLSearchParams(params).toString();
    window.open(`${endpoint}?${query}`, '_blank');
};

const ActionRow = ({ icon, title, description, onClick, primary = false }) => (
    <Box
        component="button"
        type="button"
        onClick={onClick}
        sx={{
            width: '100%',
            textAlign: 'left',
            border: `1px solid ${primary ? '#BFDBFE' : '#E2E8F0'}`,
            bgcolor: primary ? '#EFF6FF' : '#FFFFFF',
            color: '#0F172A',
            borderRadius: '12px',
            p: 1.5,
            display: 'flex',
            alignItems: 'center',
            gap: 1.5,
            cursor: 'pointer',
            transition: 'border-color 150ms ease, background-color 150ms ease, transform 150ms ease',
            '&:hover': {
                borderColor: '#1A56DB',
                bgcolor: primary ? '#DBEAFE' : '#F8FAFC',
                transform: 'translateY(-1px)',
            },
        }}
    >
        <Box sx={{ width: 34, height: 34, borderRadius: '10px', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: primary ? '#1A56DB' : '#F1F5F9', color: primary ? '#FFFFFF' : '#334155', flexShrink: 0 }}>
            {icon}
        </Box>
        <Box sx={{ minWidth: 0 }}>
            <Typography sx={{ fontWeight: 800, fontSize: '13px', color: '#0F172A' }}>{title}</Typography>
            <Typography sx={{ fontWeight: 600, fontSize: '12px', color: '#64748B' }}>{description}</Typography>
        </Box>
    </Box>
);

export default function QuickActionsHub({ compact = false }) {
    const now = new Date();

    return (
        <Box sx={CARD_STYLE}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 800, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Quick Actions Hub
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#64748B', fontSize: '12px' }}>
                        On-demand financial exports
                    </Typography>
                </Box>
                <GetAppIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            <Box sx={{ display: 'grid', gridTemplateColumns: compact ? '1fr' : { xs: '1fr', sm: '1fr 1fr' }, gap: 1.5 }}>
                <ActionRow
                    primary
                    icon={<AssessmentIcon sx={{ fontSize: 18 }} />}
                    title="Generate CSMR Report"
                    description="Monthly tenant sales report"
                    onClick={() => handleExport('/finance/reports/export', {
                        year: now.getFullYear(),
                        month: now.getMonth() + 1,
                    })}
                />
                <ActionRow
                    icon={<AssessmentIcon sx={{ fontSize: 18 }} />}
                    title="Generate Audit Report"
                    description="Compliance and audit history"
                    onClick={() => handleExport('/api/dashboard/export-audit-logs')}
                />
                <ActionRow
                    icon={<DownloadIcon sx={{ fontSize: 18 }} />}
                    title="Export Sales Data"
                    description="Raw transaction sales export"
                    onClick={() => handleExport('/api/dashboard/export-transactions')}
                />
                <ActionRow
                    icon={<DownloadIcon sx={{ fontSize: 18 }} />}
                    title="Export Reconciliation"
                    description="Reconciliation CSV extract"
                    onClick={() => handleExport('/logs/export/csv')}
                />
            </Box>
        </Box>
    );
}
