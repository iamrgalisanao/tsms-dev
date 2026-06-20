import React, { useMemo } from 'react';
import { Box, Typography, Stack } from '@mui/material';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import InsightsIcon from '@mui/icons-material/Insights';
import TransactionChart from '../../../Components/dashboard/TransactionChart';

const CARD_STYLE = {
    p: 2.5,
    borderRadius: '12px',
    border: '1px solid #E2E8F0',
    boxShadow: '0 10px 24px rgba(15,23,42,0.045), 0 1px 2px rgba(15,23,42,0.06)',
    bgcolor: '#FFFFFF',
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
    const hasSalesData = charts?.sales?.some((value) => Number(value) > 0);

    return (
        <Box sx={CARD_STYLE}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 800, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Monthly Revenue Trend
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#64748B', fontSize: '12px' }}>
                        Sales and transaction lifecycle
                    </Typography>
                </Box>
                <QueryStatsIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            {/* Summary metrics in subtle background boxes */}
            <Stack direction="row" spacing={3} sx={{ mb: 3 }}>
                <Box sx={{ bgcolor: '#F8FAFC', p: '10px 14px', borderRadius: '10px', flex: 1, border: '1px solid #EEF2F7' }}>
                    <Typography sx={{ color: '#64748B', fontWeight: 800, fontSize: '11px' }}>
                        Peak Revenue Day
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#1A56DB', fontSize: '15px', mt: 0.5 }}>
                        {chartStats.peakSalesDay}{' '}
                        <Box component="span" sx={{ fontSize: '12px', fontWeight: 500, color: '#64748B' }}>
                            ({formatCurrency(chartStats.peakSales)})
                        </Box>
                    </Typography>
                </Box>
                <Box sx={{ bgcolor: '#F8FAFC', p: '10px 14px', borderRadius: '10px', flex: 1, border: '1px solid #EEF2F7' }}>
                    <Typography sx={{ color: '#64748B', fontWeight: 800, fontSize: '11px' }}>
                        Avg Daily Revenue
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#16A34A', fontSize: '15px', mt: 0.5 }}>
                        {formatCurrency(chartStats.avgDailyRevenue)}
                    </Typography>
                </Box>
            </Stack>

            {/* Chart — direct inline integration */}
            {hasSalesData ? (
                <Box sx={{ flex: 1, minHeight: 280 }}>
                    <TransactionChart data={charts} loading={false} inline />
                </Box>
            ) : (
                <Stack flex={1} alignItems="center" justifyContent="center" spacing={1.5} sx={{ minHeight: 240, bgcolor: '#F8FAFC', border: '1px dashed #CBD5E1', borderRadius: '12px', textAlign: 'center', px: 3 }}>
                    <Box sx={{ p: 1.5, bgcolor: '#EEF2FF', borderRadius: '12px', display: 'flex', color: '#1A56DB' }}>
                        <InsightsIcon />
                    </Box>
                    <Typography sx={{ fontWeight: 800, color: '#0F172A', fontSize: '14px' }}>
                        No revenue trend for this range
                    </Typography>
                    <Typography sx={{ color: '#64748B', fontSize: '13px', maxWidth: 300 }}>
                        Try a wider date range or sync data to populate the revenue lifecycle chart.
                    </Typography>
                </Stack>
            )}
        </Box>
    );
}
