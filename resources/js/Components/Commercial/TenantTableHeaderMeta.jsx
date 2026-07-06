import React from 'react';
import { Box, Typography } from '@mui/material';

const resolveTenant = ({ selectedTenant, tenantId, tenants }) => {
    if (selectedTenant) {
        return selectedTenant;
    }

    if (!tenantId) {
        return null;
    }

    return tenants.find((tenant) => String(tenant.id) === String(tenantId)) || null;
};

export const getTenantHeaderDetails = ({ selectedTenant = null, tenantId = '', tenants = [] }) => {
    const tenant = resolveTenant({ selectedTenant, tenantId, tenants });

    return {
        tenantName: tenant?.trade_name || 'All Tenants',
        customerCode: tenant?.customer_code || (tenant ? 'N/A' : 'All Customer Codes'),
    };
};

const TenantTableHeaderMeta = ({ selectedTenant = null, tenantId = '', tenants = [] }) => {
    const { tenantName, customerCode } = getTenantHeaderDetails({ selectedTenant, tenantId, tenants });

    return (
        <Box
            sx={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 1,
                justifyContent: { xs: 'flex-start', sm: 'flex-end' },
            }}
        >
            <Box sx={{ px: 1.5, py: 0.75, bgcolor: 'action.hover', borderRadius: 1 }}>
                <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.06em', lineHeight: 1.2 }}>
                    Tenant Name
                </Typography>
                <Typography variant="caption" sx={{ display: 'block', color: 'text.primary', fontWeight: 900, lineHeight: 1.3 }}>
                    {tenantName}
                </Typography>
            </Box>
            <Box sx={{ px: 1.5, py: 0.75, bgcolor: 'action.hover', borderRadius: 1 }}>
                <Typography variant="caption" sx={{ display: 'block', color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.06em', lineHeight: 1.2 }}>
                    Customer Code
                </Typography>
                <Typography variant="caption" sx={{ display: 'block', color: 'text.primary', fontWeight: 900, lineHeight: 1.3 }}>
                    {customerCode}
                </Typography>
            </Box>
        </Box>
    );
};

export default TenantTableHeaderMeta;
