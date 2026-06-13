import React from 'react';
import { Card, CardContent, Typography, Box, Stack, LinearProgress } from '@mui/material';
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

    return (
        <Card sx={{ borderRadius: '32px', p: 2 }}>
            <CardContent>
                <Typography variant="caption" sx={{ fontSize: '12px', fontWeight: 900, color: 'grey.500', textTransform: 'uppercase', letterSpacing: '0.15em', mb: 4, display: 'block' }}>
                    System Health
                </Typography>

                <Stack spacing={4}>
                    {/* CPU Utilization */}
                    <Box>
                        <Stack direction="row" justifyContent="space-between" sx={{ mb: 1 }}>
                            <Stack direction="row" alignItems="center" spacing={1}>
                                <MemoryIcon sx={{ fontSize: 16, color: 'grey.400' }} />
                                <Typography variant="caption" sx={{ fontWeight: 'bold', color: 'grey.600' }}>CPU UTILIZATION</Typography>
                            </Stack>
                            <Typography variant="caption" sx={{ fontWeight: 900, color: stats.cpu > 80 ? 'error.main' : 'text.primary' }}>{stats.cpu}%</Typography>
                        </Stack>
                        <LinearProgress
                            variant="determinate"
                            value={stats.cpu}
                            color={getStatusColor(stats.cpu)}
                            sx={{ height: 8, borderRadius: 4, bgcolor: 'grey.100' }}
                        />
                    </Box>

                    {/* Memory Usage */}
                    <Box>
                        <Stack direction="row" justifyContent="space-between" sx={{ mb: 1 }}>
                            <Stack direction="row" alignItems="center" spacing={1}>
                                <StorageIcon sx={{ fontSize: 16, color: 'grey.400' }} />
                                <Typography variant="caption" sx={{ fontWeight: 'bold', color: 'grey.600' }}>MEMORY USAGE</Typography>
                            </Stack>
                            <Typography variant="caption" sx={{ fontWeight: 900, color: stats.memory > 80 ? 'error.main' : 'text.primary' }}>{stats.memory}%</Typography>
                        </Stack>
                        <LinearProgress
                            variant="determinate"
                            value={stats.memory}
                            color={getStatusColor(stats.memory)}
                            sx={{ height: 8, borderRadius: 4, bgcolor: 'grey.100' }}
                        />
                    </Box>

                    {/* Network Status */}
                    <Box sx={{ pt: 2, borderTop: '1px solid', borderColor: 'grey.100' }}>
                        <Typography variant="caption" sx={{ fontSize: '10px', fontWeight: 900, color: 'grey.400', display: 'block', mb: 0.5 }}>NETWORK</Typography>
                        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1 }}>
                            <LanguageIcon sx={{ fontSize: 14, color: 'success.main' }} />
                            <Typography variant="body2" sx={{ fontWeight: 'bold', color: 'success.main' }}>{stats.network}</Typography>
                        </Stack>
                        <Typography variant="caption" sx={{ fontSize: '10px', fontWeight: 700, color: 'grey.600' }}>
                            Queue backlog: {stats.queues?.backlog ?? 0} job{(stats.queues?.backlog ?? 0) === 1 ? '' : 's'}
                        </Typography>
                    </Box>
                </Stack>
            </CardContent>
        </Card>
    );
};

export default SystemHealthMonitor;
