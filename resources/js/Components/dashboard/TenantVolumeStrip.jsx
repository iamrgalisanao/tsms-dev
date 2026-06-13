import React from 'react';
import { Box, Typography, Stack, Tooltip } from '@mui/material';

const TenantVolumeStrip = ({ tenants = [], title = "TOP TENANTS (24H)" }) => {
    return (
        <Box 
            className="glass-container stagger-item" 
            sx={{ 
                p: 2, 
                mb: 4, 
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

            <Stack direction="row" spacing={4} sx={{ display: 'flex', alignItems: 'center' }}>
                {tenants.map((tenant, index) => (
                    <Tooltip key={tenant.tenant_id} title={`Total Transactions: ${tenant.count}`}>
                        <Stack direction="row" spacing={1.5} alignItems="center" sx={{ cursor: 'help' }}>
                            <Box 
                                sx={{ 
                                    width: 8, 
                                    height: 8, 
                                    borderRadius: '50%', 
                                    bgcolor: index === 0 ? '#00f2ff' : 'rgba(0,0,0,0.1)',
                                    boxShadow: index === 0 ? '0 0 10px #00f2ff' : 'none'
                                }} 
                            />
                            <Box>
                                <Typography sx={{ fontSize: '0.75rem', fontWeight: 900, color: '#101221', lineHeight: 1 }}>
                                    {tenant.tenant_id}
                                </Typography>
                                <Typography sx={{ fontSize: '0.6rem', fontWeight: 700, color: 'text.secondary', opacity: 0.5 }}>
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
