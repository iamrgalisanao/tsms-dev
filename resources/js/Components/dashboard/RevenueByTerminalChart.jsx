import React from 'react';
import { Card, CardContent, Typography, Box, CircularProgress, Stack, LinearProgress } from '@mui/material';

const RevenueByTerminalChart = ({ data, loading }) => {
    if (loading) return (
        <Card sx={{ height: '100%', borderRadius: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 250 }}>
            <CircularProgress size={32} color="primary" />
        </Card>
    );

    const items = (data || [])
        .map((item) => ({
            ...item,
            total_sales: Number(item.total_sales || 0),
            terminalLabel: item.serial_number || item.terminal_id || 'N/A',
            tenantLabel: item.trade_name || item.tenant_name || 'Unknown Tenant'
        }))
        .sort((a, b) => b.total_sales - a.total_sales)
        .slice(0, 6);

    const maxSales = Math.max(...items.map((item) => item.total_sales), 1);

    return (
        <Card sx={{ height: '100%', borderRadius: '32px', p: 2 }}>
            <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Typography
                    variant="caption"
                    sx={{
                        fontSize: '12px',
                        fontWeight: 900,
                        color: 'grey.500',
                        textTransform: 'uppercase',
                        letterSpacing: '0.15em',
                        mb: 4,
                        display: 'block'
                    }}
                >
                    Top Performing Terminals
                </Typography>
                <Box sx={{ flex: 1, minHeight: 250, position: 'relative' }}>
                    {items.length === 0 ? (
                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                            No terminal performance data available for this period.
                        </Typography>
                    ) : (
                        <Stack spacing={2}>
                            {items.map((item, index) => (
                                <Box key={`${item.terminalLabel}-${index}`} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, p: 1.5 }}>
                                    <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
                                        <Box>
                                            <Typography variant="body2" sx={{ fontWeight: 800 }}>
                                                #{index + 1} {item.terminalLabel}
                                            </Typography>
                                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                                                {item.tenantLabel}
                                            </Typography>
                                        </Box>
                                        <Typography variant="body2" sx={{ fontWeight: 900, fontFamily: 'monospace' }}>
                                            {new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(item.total_sales)}
                                        </Typography>
                                    </Stack>
                                    <LinearProgress
                                        variant="determinate"
                                        value={Math.round((item.total_sales / maxSales) * 100)}
                                        sx={{ height: 8, borderRadius: 4 }}
                                    />
                                </Box>
                            ))}
                        </Stack>
                    )}
                </Box>
            </CardContent>
        </Card>
    );
};

export default RevenueByTerminalChart;
