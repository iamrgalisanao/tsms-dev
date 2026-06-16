import React, { useMemo } from 'react';
import { Card, CardContent, Typography, Box, Grid, Tooltip } from '@mui/material';
import DnsIcon from '@mui/icons-material/Dns';

const WorkerHealthGrid = ({ workersStatus = '12/12' }) => {
    // Determine active worker status array. Assume 12 workers total, worker 4 is restarting/warning.
    const workerNodes = useMemo(() => {
        return [
            { id: 1, name: 'Worker Node 1', status: 'healthy' },
            { id: 2, name: 'Worker Node 2', status: 'healthy' },
            { id: 3, name: 'Worker Node 3', status: 'healthy' },
            { id: 4, name: 'Worker Node 4', status: 'restarting' },
            { id: 5, name: 'Worker Node 5', status: 'healthy' },
            { id: 6, name: 'Worker Node 6', status: 'healthy' },
            { id: 7, name: 'Worker Node 7', status: 'healthy' },
            { id: 8, name: 'Worker Node 8', status: 'healthy' },
            { id: 9, name: 'Worker Node 9', status: 'healthy' },
            { id: 10, name: 'Worker Node 10', status: 'healthy' },
            { id: 11, name: 'Worker Node 11', status: 'healthy' },
            { id: 12, name: 'Worker Node 12', status: 'healthy' }
        ];
    }, []);

    const healthyCount = workerNodes.filter(n => n.status === 'healthy').length;

    return (
        <Card sx={{ borderRadius: 3, border: '1px solid', borderColor: 'divider', height: '100%', bgcolor: 'white', display: 'flex', flexDirection: 'column', width: '100%' }}>
            <CardContent sx={{ p: 3, flexGrow: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                <Box>
                    <Typography variant="h6" sx={{ fontWeight: 900, mb: 1, color: 'primary.main', display: 'flex', alignItems: 'center' }}>
                        <DnsIcon sx={{ mr: 1 }} /> Worker Nodes Pool
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 700, mb: 3, display: 'block' }}>
                        Dynamic queue dispatch threads
                    </Typography>
                </Box>

                {/* Grid of Nodes */}
                <Box sx={{ my: 3 }}>
                    <Grid container spacing={2.5} justifyContent="center" sx={{ maxWidth: '280px', mx: 'auto' }}>
                        {workerNodes.map((node) => {
                            const isHealthy = node.status === 'healthy';
                            return (
                                <Grid item xs={3} key={node.id} sx={{ display: 'flex', justifyContent: 'center' }}>
                                    <Tooltip title={`${node.name}: ${node.status.toUpperCase()}`} arrow>
                                        <Box
                                            role="status"
                                            aria-label={`${node.name} is ${node.status}`}
                                            sx={{
                                                width: '28px',
                                                height: '28px',
                                                borderRadius: '8px',
                                                bgcolor: isHealthy ? 'rgba(76, 175, 80, 0.15)' : 'rgba(255, 152, 0, 0.15)',
                                                border: '2px solid',
                                                borderColor: isHealthy ? '#00e676' : '#feb700',
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'center',
                                                fontWeight: 'bold',
                                                fontSize: '0.68rem',
                                                color: isHealthy ? '#00c853' : '#e65100',
                                                boxShadow: isHealthy 
                                                    ? '0 0 10px rgba(0, 230, 118, 0.2)' 
                                                    : '0 0 10px rgba(254, 183, 0, 0.2)',
                                                cursor: 'pointer',
                                                transition: 'all 0.2s',
                                                '&:hover': {
                                                    transform: 'scale(1.1)',
                                                    boxShadow: isHealthy 
                                                        ? '0 0 15px rgba(0, 230, 118, 0.4)' 
                                                        : '0 0 15px rgba(254, 183, 0, 0.4)'
                                                }
                                            }}
                                        >
                                            {node.id}
                                        </Box>
                                    </Tooltip>
                                </Grid>
                            );
                        })}
                    </Grid>
                </Box>

                <Box sx={{ borderTop: '1px solid', borderColor: 'divider', pt: 2, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.secondary' }}>Pool Health</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 900, color: healthyCount === workerNodes.length ? 'success.main' : 'warning.main' }}>
                        {healthyCount} / {workerNodes.length} Healthy
                    </Typography>
                </Box>
            </CardContent>
        </Card>
    );
};

export default WorkerHealthGrid;
