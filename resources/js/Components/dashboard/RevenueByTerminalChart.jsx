import React from 'react';
import { Card, CardContent, Typography, Box, CircularProgress, Stack, LinearProgress, useTheme } from '@mui/material';

const RevenueByTerminalChart = ({ data, loading }) => {
    const theme = useTheme();

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
        <Card sx={{
            height: '100%',
            transition: 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)',
            '&:hover': {
                transform: 'translateY(-2px)',
                boxShadow: theme.custom?.shadows?.cardHover || '0 12px 40px rgba(29, 67, 155, 0.08)',
            }
        }}>
            <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column', p: '20px !important' }}>
                <Typography
                    variant="caption"
                    sx={{
                        fontSize: '0.68rem',
                        fontWeight: 800,
                        color: 'text.secondary',
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        mb: 3,
                        display: 'block'
                    }}
                >
                    Top Performing Terminals
                </Typography>
                <Box sx={{ flex: 1, minHeight: 250, position: 'relative' }}>
                    {items.length === 0 ? (
                        <Box sx={{ height: '100%', minHeight: 250, display: 'flex', alignItems: 'center', justifyContent: 'center', border: '1px dashed', borderColor: 'divider', borderRadius: 3, p: 2, textAlign: 'center' }}>
                            <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 600 }}>
                                No terminal performance data available for this period.
                            </Typography>
                        </Box>
                    ) : (
                        <Stack spacing={2}>
                            {items.map((item, index) => (
                                <Box
                                    key={`${item.terminalLabel}-${index}`}
                                    sx={{
                                        border: '1px solid rgba(255, 255, 255, 0.4)',
                                        borderRadius: '16px',
                                        p: 2,
                                        bgcolor: 'rgba(255, 255, 255, 0.35)',
                                        transition: 'all 0.2s',
                                        '&:hover': {
                                            bgcolor: 'rgba(255, 255, 255, 0.65)',
                                            transform: 'translateY(-1px)',
                                            boxShadow: '0 4px 12px rgba(29, 67, 155, 0.02)'
                                        }
                                    }}
                                >
                                    <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
                                        <Box>
                                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.primary' }}>
                                                #{index + 1} {item.terminalLabel}
                                            </Typography>
                                            <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 600 }}>
                                                {item.tenantLabel}
                                            </Typography>
                                        </Box>
                                        <Typography variant="body2" sx={{ fontWeight: 900, fontFamily: 'monospace', color: 'text.primary', fontVariantNumeric: 'tabular-nums' }}>
                                            {new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(item.total_sales)}
                                        </Typography>
                                    </Stack>
                                    <LinearProgress
                                        variant="determinate"
                                        value={Math.round((item.total_sales / maxSales) * 100)}
                                        sx={{
                                            height: 6,
                                            borderRadius: 3,
                                            bgcolor: 'rgba(29, 67, 155, 0.08)',
                                            '& .MuiLinearProgress-bar': {
                                                borderRadius: 3,
                                                background: 'linear-gradient(90deg, #1D439B 0%, #4169c1 100%)',
                                            }
                                        }}
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
