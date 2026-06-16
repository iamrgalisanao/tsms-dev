import React from 'react';
import { Grid, Paper, Box, Typography, Stack } from '@mui/material';
import DnsIcon from '@mui/icons-material/Dns';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import GroupsIcon from '@mui/icons-material/Groups';

const PipelineOverviewCard = ({
    systemStatus = 'OPERATIONAL',
    activeTenantsCount = 0,
    failedTxCount = 0,
    quarantinedCount = 0,
    workersStatus = '12/12'
}) => {
    const getStatusColor = (status) => {
        if (status === 'OPERATIONAL') return '#00e676';
        if (status === 'DEGRADED') return '#feb700';
        return '#ff1744';
    };

    const metrics = [
        {
            title: 'Pipeline Ingestion',
            value: systemStatus,
            color: getStatusColor(systemStatus),
            icon: <CheckCircleIcon style={{ color: getStatusColor(systemStatus), fontSize: 28 }} />,
            sub: 'System core operational status'
        },
        {
            title: 'Active Ingestors',
            value: activeTenantsCount.toString(),
            color: '#00f2ff',
            icon: <GroupsIcon style={{ color: '#00f2ff', fontSize: 28 }} />,
            sub: 'Active tenant devices sending data'
        },
        {
            title: 'Failed Transactions',
            value: failedTxCount.toString(),
            color: failedTxCount > 0 ? '#ff1744' : '#00e676',
            icon: <ErrorOutlineIcon style={{ color: failedTxCount > 0 ? '#ff1744' : '#00e676', fontSize: 28 }} />,
            sub: 'Permanently failed dispatches'
        },
        {
            title: 'Quarantined Items',
            value: quarantinedCount.toString(),
            color: quarantinedCount > 0 ? '#feb700' : '#00e676',
            icon: <ReceiptLongIcon style={{ color: quarantinedCount > 0 ? '#feb700' : '#00e676', fontSize: 28 }} />,
            sub: 'Suspended validation holds'
        },
        {
            title: 'Worker Node Status',
            value: workersStatus,
            color: '#00e676',
            icon: <DnsIcon style={{ color: '#00e676', fontSize: 28 }} />,
            sub: 'Active / total processing workers'
        }
    ];

    return (
        <Paper 
            className="glass-container" 
            sx={{ 
                p: 4, 
                bgcolor: 'rgba(255, 255, 255, 0.85)', 
                border: '1px solid', 
                borderColor: 'divider',
                borderRadius: '24px',
                boxShadow: '0 8px 32px rgba(0, 0, 0, 0.04)'
            }}
            role="region"
            aria-label="Operations Overview Dashboard"
        >
            <Typography variant="h6" sx={{ fontWeight: 1000, mb: 3, letterSpacing: '0.05em', color: '#101221' }}>
                OPERATIONS OVERVIEW
            </Typography>
            <Grid container spacing={3}>
                {metrics.map((m) => (
                    <Grid item xs={12} sm={6} md={2.4} key={m.title}>
                        <Box 
                            sx={{ 
                                p: 2.5, 
                                borderRadius: '18px', 
                                border: '1px solid', 
                                borderColor: 'divider', 
                                bgcolor: 'white',
                                minHeight: '115px',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'space-between',
                                transition: 'all 0.3s',
                                '&:hover': {
                                    boxShadow: '0 10px 20px rgba(0,0,0,0.02)',
                                    transform: 'translateY(-2px)'
                                }
                            }}
                        >
                            <Stack direction="row" justifyContent="space-between" alignItems="center">
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 900, fontSize: '0.65rem', letterSpacing: '0.08em', textTransform: 'uppercase' }}>
                                    {m.title}
                                </Typography>
                                {m.icon}
                            </Stack>
                            <Box sx={{ mt: 1.5 }}>
                                <Typography variant="h4" sx={{ fontWeight: 1000, color: '#101221', letterSpacing: '-0.02em', fontSize: '1.75rem' }}>
                                    {m.value}
                                </Typography>
                                <Typography variant="caption" sx={{ color: 'text.secondary', fontSize: '0.62rem', fontWeight: 700, mt: 0.5, display: 'block' }}>
                                    {m.sub}
                                </Typography>
                            </Box>
                        </Box>
                    </Grid>
                ))}
            </Grid>
        </Paper>
    );
};

export default PipelineOverviewCard;
