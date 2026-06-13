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
                {tenants.map((tenant, index) => (
                    <Tooltip key={tenant.tenant_id} title={`Total Transactions: ${tenant.count}`}>
                        <Stack 
                            direction="row" 
                            spacing={1.5} 
                            alignItems="center" 
                            sx={{ 
                                cursor: 'help',
                                bgcolor: 'rgba(0,0,0,0.02)',
                                px: 1.5,
                                py: 0.75,
                                borderRadius: 2,
                                border: '1px solid',
                                borderColor: index === 0 ? 'primary.light' : 'divider',
                                boxShadow: index === 0 ? '0 2px 8px rgba(0,242,255,0.08)' : 'none'
                            }}
                        >
                            <Typography 
                                variant="caption" 
                                sx={{ 
                                    fontWeight: 900, 
                                    color: index === 0 ? 'primary.main' : 'text.secondary',
                                    fontSize: '0.7rem'
                                }}
                            >
                                #{index + 1}
                            </Typography>
                            <Box>
                                <Typography sx={{ fontSize: '0.75rem', fontWeight: 900, color: '#101221', lineHeight: 1, mb: 0.2 }}>
                                    Terminal {tenant.tenant_id}
                                </Typography>
                                <Typography sx={{ fontSize: '0.6rem', fontWeight: 800, color: 'text.secondary', opacity: 0.7 }}>
                                    {tenant.count} units
                                </Typography>
                            </Box>
                        </Stack>
                    </Tooltip>
                ))}
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
