import React, { useMemo } from 'react';
import { Box, Typography, Stack } from '@mui/material';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import TransactionChart from '../../../Components/dashboard/TransactionChart';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
};

const formatCurrency = (val) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val ?? 0);

export default function RevenueTrendCard({ charts }) {
    const chartStats = useMemo(() => {
        if (!charts?.sales || charts.sales.length === 0) {
            return { peakSales: 0, peakSalesDay: 'N/A', avgDailyRevenue: 0 };
        }
        const peakSales = Math.max(...charts.sales);
        const peakSalesIndex = charts.sales.indexOf(peakSales);
        const peakSalesDay = peakSalesIndex !== -1 ? charts.labels?.[peakSalesIndex] : 'N/A';
        const avgDailyRevenue = charts.sales.reduce((a, b) => a + b, 0) / charts.sales.length;
        return { peakSales, peakSalesDay, avgDailyRevenue };
    }, [charts]);

    return (
        <Box sx={GLASS}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Monthly Revenue Trend
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        Sales and transaction lifecycle
                    </Typography>
                </Box>
                <QueryStatsIcon sx={{ color: 'text.disabled', fontSize: 20 }} />
            </Stack>

            {/* Summary metrics */}
            <Stack direction="row" spacing={4} sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.06em' }}>
                        Peak Revenue Day
                    </Typography>
                    <Typography sx={{ fontWeight: 950, color: 'primary.main', fontSize: '0.95rem', mt: 0.25 }}>
                        {chartStats.peakSalesDay}{' '}
                        <Box component="span" sx={{ fontSize: '0.75rem', fontWeight: 700, color: 'text.secondary' }}>
                            ({formatCurrency(chartStats.peakSales)})
                        </Box>
                    </Typography>
                </Box>
                <Box>
                    <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 800, textTransform: 'uppercase', fontSize: '0.6rem', letterSpacing: '0.06em' }}>
                        Avg Daily Revenue
                    </Typography>
                    <Typography sx={{ fontWeight: 950, color: 'success.main', fontSize: '0.95rem', mt: 0.25 }}>
                        {formatCurrency(chartStats.avgDailyRevenue)}
                    </Typography>
                </Box>
            </Stack>

            {/* Chart — directly inside the card, no inner wrapper */}
            <Box sx={{ flex: 1, minHeight: 300 }}>
                <TransactionChart data={charts} loading={false} />
            </Box>
        </Box>
    );
}
