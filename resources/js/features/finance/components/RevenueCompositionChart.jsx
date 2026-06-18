import React, { useMemo } from 'react';
import { Box, Stack, Typography } from '@mui/material';
import { formatCurrency } from './financeFormat';

const RevenueCompositionChart = ({ data }) => {
    const categories = useMemo(() => [
        { name: 'Net Sales', value: data?.net_sales || 0, color: '#10B981' },
        { name: 'VAT', value: data?.vat || 0, color: '#F59E0B' },
        { name: 'Tax Exempt', value: data?.tax_exempt || 0, color: '#3B82F6' },
        { name: 'Refunds', value: data?.refunds || 0, color: '#EF4444' },
        { name: 'Discounts', value: data?.discounts || 0, color: '#8B5CF6' },
    ], [data]);

    const total = useMemo(() => categories.reduce((sum, category) => sum + category.value, 0), [categories]);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    let accumulatedPercent = 0;

    return (
        <Box sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row' }, alignItems: 'center', gap: 4, height: '100%', py: 2 }}>
            <Box sx={{ position: 'relative', width: 180, height: 180, display: 'flex', justifyContent: 'center', alignItems: 'center', flexShrink: 0 }}>
                <svg width="100%" height="100%" viewBox="0 0 120 120" style={{ transform: 'rotate(-90deg)' }}>
                    <circle cx="60" cy="60" r={radius} fill="transparent" stroke="rgba(229, 231, 245, 0.7)" strokeWidth="12" />
                    {total > 0 && categories.map((category) => {
                        if (category.value <= 0) return null;
                        const percent = (category.value / total) * 100;
                        const rotation = (accumulatedPercent / 100) * 360;
                        accumulatedPercent += percent;

                        return (
                            <circle
                                key={category.name}
                                cx="60"
                                cy="60"
                                r={radius}
                                fill="transparent"
                                stroke={category.color}
                                strokeWidth="12"
                                strokeDasharray={circumference}
                                strokeDashoffset={circumference - (percent * circumference) / 100}
                                style={{
                                    transformOrigin: '60px 60px',
                                    transform: `rotate(${rotation}deg)`,
                                }}
                            />
                        );
                    })}
                </svg>
                <Box sx={{ position: 'absolute', textAlign: 'center', width: 120 }}>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.65rem' }}>
                        Total Revenue
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 900, color: 'text.primary', fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {formatCurrency(total)}
                    </Typography>
                </Box>
            </Box>
            <Stack spacing={1.5} sx={{ flex: 1, width: '100%' }}>
                {categories.map((category) => {
                    const percent = total > 0 ? ((category.value / total) * 100).toFixed(1) : 0;
                    return (
                        <Box key={category.name} sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid rgba(226, 232, 240, 0.8)', pb: 0.75, gap: 2 }}>
                            <Stack direction="row" spacing={1.5} alignItems="center">
                                <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: category.color }} />
                                <Typography variant="body2" sx={{ fontWeight: 750, color: 'text.primary' }}>{category.name}</Typography>
                            </Stack>
                            <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.secondary', textAlign: 'right' }}>
                                {formatCurrency(category.value)} <Box component="span" sx={{ color: 'text.disabled', fontWeight: 600, fontSize: '0.75rem', ml: 0.5 }}>({percent}%)</Box>
                            </Typography>
                        </Box>
                    );
                })}
            </Stack>
        </Box>
    );
};

export default RevenueCompositionChart;
