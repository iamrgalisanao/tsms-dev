import React, { useMemo } from 'react';
import { Box, Typography, Stack } from '@mui/material';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import TransactionChart from '../../../Components/dashboard/TransactionChart';

const CARD_STYLE = {
    p: 3,
    borderRadius: '10px',
    border: '1px solid #E8ECF4',
    boxShadow: '0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04)',
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

    return (
        <Box sx={CARD_STYLE}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 700, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Monthly Revenue Trend
                    </Typography>
                    <Typography sx={{ fontWeight: 600, color: '#64748B', textTransform: 'uppercase', letterSpacing: '0.08em', fontSize: '11px' }}>
                        Sales and transaction lifecycle
                    </Typography>
                </Box>
                <QueryStatsIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            {/* Summary metrics in subtle background boxes */}
            <Stack direction="row" spacing={3} sx={{ mb: 3 }}>
                <Box sx={{ bgcolor: '#F8FAFC', p: '10px 14px', borderRadius: '6px', flex: 1 }}>
                    <Typography sx={{ color: '#64748B', fontWeight: 600, textTransform: 'uppercase', fontSize: '11px', letterSpacing: '0.06em' }}>
                        Peak Revenue Day
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#1A56DB', fontSize: '15px', mt: 0.5 }}>
                        {chartStats.peakSalesDay}{' '}
                        <Box component="span" sx={{ fontSize: '12px', fontWeight: 500, color: '#64748B' }}>
                            ({formatCurrency(chartStats.peakSales)})
                        </Box>
                    </Typography>
                </Box>
                <Box sx={{ bgcolor: '#F8FAFC', p: '10px 14px', borderRadius: '6px', flex: 1 }}>
                    <Typography sx={{ color: '#64748B', fontWeight: 600, textTransform: 'uppercase', fontSize: '11px', letterSpacing: '0.06em' }}>
                        Avg Daily Revenue
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#16A34A', fontSize: '15px', mt: 0.5 }}>
                        {formatCurrency(chartStats.avgDailyRevenue)}
                    </Typography>
                </Box>
            </Stack>

            {/* Chart — direct inline integration */}
            <Box sx={{ flex: 1, minHeight: 300 }}>
                <TransactionChart data={charts} loading={false} inline />
            </Box>
        </Box>
    );
}
