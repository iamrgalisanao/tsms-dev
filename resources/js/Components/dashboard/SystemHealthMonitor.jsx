import React from 'react';
import { Card, CardContent, Typography, Box, Stack, LinearProgress, Grid, Chip } from '@mui/material';
import MemoryIcon from '@mui/icons-material/Memory';
import StorageIcon from '@mui/icons-material/Storage';
import LanguageIcon from '@mui/icons-material/Language';

const SystemHealthMonitor = ({ health, loading }) => {
    if (loading) return (
        <Card sx={{ height: 320, borderRadius: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <LinearProgress sx={{ width: '80%' }} />
        </Card>
    );

    const stats = health || { cpu: 0, memory: 0, network: 'Unknown', queues: { backlog: 0 } };

    const getStatusColor = (value) => {
        if (value > 80) return 'error';
        if (value > 50) return 'warning';
        return 'success';
    };

    const getStatusLabel = (value) => {
        if (value > 80) return 'Critical';
        if (value > 50) return 'Warning';
        return 'Healthy';
    };

    const queueBacklog = Number(stats.queues?.backlog ?? 0);
    const networkHealthy = String(stats.network || '').toLowerCase() === 'healthy';

    const compactItems = [
        {
            key: 'cpu',
            icon: <MemoryIcon sx={{ fontSize: 16, color: 'grey.500' }} />,
            label: 'CPU',
            value: `${stats.cpu}% - ${getStatusLabel(stats.cpu)}`,
            progress: stats.cpu,
            color: getStatusColor(stats.cpu)
        },
        {
            key: 'memory',
            icon: <StorageIcon sx={{ fontSize: 16, color: 'grey.500' }} />,
            label: 'Memory',
            value: `${stats.memory}% - ${getStatusLabel(stats.memory)}`,
            progress: stats.memory,
            color: getStatusColor(stats.memory)
        },
        {
            key: 'queue',
            icon: <StorageIcon sx={{ fontSize: 16, color: 'grey.500' }} />,
            label: 'Queue',
            value: `${queueBacklog} - ${queueBacklog > 20 ? 'Critical' : queueBacklog > 5 ? 'Warning' : 'Healthy'}`,
            progress: Math.min(queueBacklog, 100),
            color: queueBacklog > 20 ? 'error' : queueBacklog > 5 ? 'warning' : 'success'
        },
        {
            key: 'network',
            icon: <LanguageIcon sx={{ fontSize: 16, color: 'grey.500' }} />,
            label: 'Network',
            value: networkHealthy ? 'Healthy' : 'Critical',
            progress: null,
            color: networkHealthy ? 'success' : 'error'
        }
    ];

    return (
        <Card sx={{ borderRadius: '24px', p: 1.5 }}>
            <CardContent sx={{ p: '12px !important' }}>
                <Grid container spacing={1.5}>
                    {compactItems.map((item) => (
                        <Grid item xs={12} sm={6} lg={3} key={item.key}>
                            <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, p: 1.5, bgcolor: 'grey.50' }}>
                                <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
                                    <Stack direction="row" alignItems="center" spacing={1}>
                                        {item.icon}
                                        <Typography variant="caption" sx={{ fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                                            {item.label}
                                        </Typography>
                                    </Stack>
                                    <Chip
                                        size="small"
                                        label={item.value}
                                        color={item.color}
                                        sx={{ fontWeight: 800, height: 22 }}
                                    />
                                </Stack>
                                {item.progress !== null && (
                                    <LinearProgress
                                        variant="determinate"
                                        value={item.progress}
                                        color={item.color}
                                        sx={{ height: 6, borderRadius: 3, bgcolor: 'grey.200' }}
                                    />
                                )}
                            </Box>
                        </Grid>
                    ))}
                </Grid>
            </CardContent>
        </Card>
    );
};

export default SystemHealthMonitor;
