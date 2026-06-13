import React from 'react';
import { Box, Typography, Stack, Tooltip } from '@mui/material';

const TenantVolumeStrip = ({ tenants = [], title = "TOP TENANTS (24H)" }) => {
    return (
        <Box 
            className="glass-container stagger-item" 
            sx={{ 
                p: 2, 
                mb: 3, // Tightened vertical gap (24px spacing)
                display: 'flex', 
                alignItems: 'center', 
                overflowX: 'auto',
                bgcolor: 'rgba(255, 255, 255, 0.5)',
                '&::-webkit-scrollbar': { display: 'none' }
            }}
        >
            <Typography 
                sx={{ 
                    fontSize: '0.65rem', 
                    fontWeight: 1000, 
                    color: 'text.secondary', 
                    mr: 3, 
                    whiteSpace: 'nowrap',
                    letterSpacing: '0.1em',
                    opacity: 0.6
                }}
            >
                {title}
            </Typography>

            <Stack direction="row" spacing={2} sx={{ display: 'flex', alignItems: 'center' }}>
                {(() => {
                    const counts = tenants.map(t => Number(t.count || 0));
                    const avgCount = counts.length > 0 ? counts.reduce((a, b) => a + b, 0) / counts.length : 0;
                    
                    return tenants.map((tenant, index) => {
                        const isAnomaly = tenants.length > 1 && tenant.count < avgCount * 0.4 && avgCount > 10;
                        const tooltipText = isAnomaly 
                            ? `Anomaly Detected: Low Throughput (Avg: ${Math.round(avgCount)} units)` 
                            : `Total Transactions: ${tenant.count}`;

                        return (
                            <Tooltip key={tenant.tenant_id} title={tooltipText}>
                                <Stack 
                                    direction="row" 
                                    spacing={1.5} 
                                    alignItems="center" 
                                    sx={{ 
                                        cursor: 'help',
                                        bgcolor: isAnomaly ? 'rgba(254, 183, 0, 0.05)' : 'rgba(0,0,0,0.02)',
                                        px: 1.5,
                                        py: 0.75,
                                        borderRadius: 2,
                                        border: '1.5px solid',
                                        borderColor: isAnomaly ? 'warning.main' : (index === 0 ? 'primary.light' : 'divider'),
                                        boxShadow: isAnomaly ? '0 2px 8px rgba(254, 183, 0, 0.12)' : (index === 0 ? '0 2px 8px rgba(0,242,255,0.08)' : 'none')
                                    }}
                                >
                                    <Typography 
                                        variant="caption" 
                                        sx={{ 
                                            fontWeight: 900, 
                                            color: isAnomaly ? 'warning.main' : (index === 0 ? 'primary.main' : 'text.secondary'),
                                            fontSize: '0.7rem'
                                        }}
                                    >
                                        {isAnomaly ? `⚠️ #${index + 1}` : `#${index + 1}`}
                                    </Typography>
                                    <Box>
                                        <Typography sx={{ fontSize: '0.75rem', fontWeight: 900, color: isAnomaly ? 'warning.dark' : '#101221', lineHeight: 1, mb: 0.2 }}>
                                            Terminal {tenant.tenant_id}
                                        </Typography>
                                        <Typography sx={{ fontSize: '0.6rem', fontWeight: 800, color: isAnomaly ? 'warning.dark' : 'text.secondary', opacity: 0.8 }}>
                                            {tenant.count} units {isAnomaly && '(Low)'}
                                        </Typography>
                                    </Box>
                                </Stack>
                            </Tooltip>
                        );
                    });
                })()}
                {tenants.length === 0 && (
                    <Typography sx={{ fontSize: '0.75rem', fontWeight: 600, opacity: 0.4 }}>
                        Awaiting data ingestion...
                    </Typography>
                )}
            </Stack>
        </Box>
    );
};

export default TenantVolumeStrip;
