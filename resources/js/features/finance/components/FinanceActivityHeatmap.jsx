import React, { useMemo } from 'react';
import { Box, Stack, Tooltip, Typography } from '@mui/material';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
const COLORS = ['#f8fafc', '#dbeafe', '#93c5fd', '#38bdf8', '#0f766e'];

const CARD_STYLE = {
    p: 2.5,
    borderRadius: '12px',
    border: '1px solid #E2E8F0',
    boxShadow: '0 10px 24px rgba(15,23,42,0.045), 0 1px 2px rgba(15,23,42,0.06)',
    bgcolor: '#FFFFFF',
    width: '100%',
    mb: 3,
};

const formatHour = (hour) => `${String(hour).padStart(2, '0')}:00`;
const formatCurrency = (value) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value ?? 0);

const getIntensity = (value, peak) => {
    if (!value) return 0;
    if (!peak) return 1;

    const ratio = value / peak;
    if (ratio >= 0.85) return 4;
    if (ratio >= 0.55) return 3;
    if (ratio >= 0.25) return 2;
    return 1;
};

export default function FinanceActivityHeatmap({ charts, dateRange }) {
    const heatmap = useMemo(() => {
        const cells = Array.isArray(charts?.cells) ? charts.cells : [];
        const bySlot = new Map(cells.map((cell) => [`${cell.day_index}-${cell.hour}`, cell]));
        const peakTransactions = Math.max(...cells.map((cell) => Number(cell.transactions || 0)), 0);
        const totalTransactions = cells.reduce((sum, cell) => sum + Number(cell.transactions || 0), 0);
        const peakCell = cells.reduce((current, cell) => {
            if (!current) return cell;
            return Number(cell.transactions || 0) > Number(current.transactions || 0) ? cell : current;
        }, null);

        return {
            rows: DAYS.map((day, dayIndex) => ({
                day,
                cells: HOURS.map((hour) => {
                    const raw = bySlot.get(`${dayIndex}-${hour}`);
                    const transactions = Number(raw?.transactions || 0);
                    const grossSales = Number(raw?.gross_sales || 0);
                    const netSales = Number(raw?.net_sales || 0);

                    return {
                        day,
                        hour,
                        transactions,
                        grossSales,
                        netSales,
                        terminalCount: Number(raw?.terminal_count || 0),
                        tenantCount: Number(raw?.tenant_count || 0),
                        hasRecord: Boolean(raw),
                        intensity: getIntensity(transactions, peakTransactions),
                        isPeak: raw && peakCell && Number(raw.transactions || 0) === Number(peakCell.transactions || 0),
                    };
                }),
            })),
            peakTransactions,
            peakLabel: peakCell ? `${DAYS[Number(peakCell.day_index || 0)]} ${formatHour(Number(peakCell.hour || 0))}` : 'No activity yet',
            totalTransactions,
        };
    }, [charts]);

    const periodLabel = `Last ${dateRange || 7} days`;

    return (
        <Box sx={CARD_STYLE}>
            <Stack
                direction={{ xs: 'column', md: 'row' }}
                justifyContent="space-between"
                alignItems={{ xs: 'flex-start', md: 'flex-start' }}
                spacing={2}
                sx={{ mb: 2.5 }}
            >
                <Box>
                    <Typography sx={{ fontWeight: 900, fontSize: '13px', color: '#1A56DB', letterSpacing: '0.12em', textTransform: 'uppercase', mb: 1 }}>
                        Activity Heatmap
                    </Typography>
                    <Stack direction="row" spacing={4} flexWrap="wrap" useFlexGap>
                        <Box>
                            <Typography sx={{ color: '#64748B', fontWeight: 800, fontSize: '11px', textTransform: 'uppercase' }}>
                                Total Transactions
                            </Typography>
                            <Typography sx={{ fontWeight: 900, fontSize: '28px', color: '#0F172A', fontVariantNumeric: 'tabular-nums' }}>
                                {heatmap.totalTransactions.toLocaleString()}
                            </Typography>
                        </Box>
                        <Box>
                            <Typography sx={{ color: '#64748B', fontWeight: 800, fontSize: '11px', textTransform: 'uppercase' }}>
                                Peak Hour / Day
                            </Typography>
                            <Typography sx={{ fontWeight: 900, fontSize: '18px', color: '#0F172A' }}>
                                {heatmap.peakLabel}
                            </Typography>
                        </Box>
                    </Stack>
                    <Typography sx={{ mt: 1, color: '#64748B', fontSize: '13px' }}>
                        Weekly transaction pattern for {periodLabel}.
                    </Typography>
                </Box>
                <Box sx={{ px: 1.5, py: 0.75, borderRadius: '999px', bgcolor: '#ECFDF5', color: '#0F766E', fontWeight: 900, fontSize: '12px' }}>
                    Peak {heatmap.peakTransactions.toLocaleString()}
                </Box>
            </Stack>

            <Box sx={{ overflowX: 'auto', pb: 1 }}>
                <Box sx={{ minWidth: 1040 }}>
                    <Box
                        sx={{
                            display: 'grid',
                            gridTemplateColumns: '76px repeat(24, minmax(30px, 1fr))',
                            gap: '6px',
                            mb: 1,
                        }}
                    >
                        <Box />
                        {HOURS.map((hour) => (
                            <Typography key={hour} sx={{ fontSize: '10px', color: '#64748B', textAlign: 'center', fontWeight: 700 }}>
                                {formatHour(hour)}
                            </Typography>
                        ))}
                    </Box>

                    <Stack spacing={0.75}>
                        {heatmap.rows.map((row) => (
                            <Box
                                key={row.day}
                                sx={{
                                    display: 'grid',
                                    gridTemplateColumns: '76px repeat(24, minmax(30px, 1fr))',
                                    gap: '6px',
                                    alignItems: 'center',
                                }}
                            >
                                <Typography sx={{ fontSize: '11px', color: '#475569', fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                                    {row.day}
                                </Typography>
                                {row.cells.map((cell) => (
                                    <Tooltip
                                        key={`${cell.day}-${cell.hour}`}
                                        arrow
                                        title={
                                            <Box>
                                                <Typography sx={{ fontWeight: 900, fontSize: '12px' }}>
                                                    {cell.day} {formatHour(cell.hour)}
                                                </Typography>
                                                <Typography sx={{ fontSize: '12px' }}>{cell.transactions.toLocaleString()} transactions</Typography>
                                                <Typography sx={{ fontSize: '12px' }}>{formatCurrency(cell.grossSales)} gross sales</Typography>
                                                <Typography sx={{ fontSize: '12px' }}>{formatCurrency(cell.netSales)} net sales</Typography>
                                                <Typography sx={{ fontSize: '12px' }}>
                                                    {cell.tenantCount} tenants, {cell.terminalCount} terminals
                                                </Typography>
                                            </Box>
                                        }
                                    >
                                        <Box
                                            component="span"
                                            sx={{
                                                display: 'block',
                                                aspectRatio: '1 / 1',
                                                minHeight: 30,
                                                borderRadius: '7px',
                                                bgcolor: COLORS[cell.intensity],
                                                border: cell.isPeak ? '2px solid #0F766E' : '1px solid #E2E8F0',
                                                boxShadow: cell.isPeak ? '0 0 0 2px rgba(15, 118, 110, 0.12)' : 'none',
                                            }}
                                        />
                                    </Tooltip>
                                ))}
                            </Box>
                        ))}
                    </Stack>
                </Box>
            </Box>

            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 2 }}>
                <Typography sx={{ color: '#64748B', fontSize: '12px' }}>
                    Showing weekly activity for {periodLabel}.
                </Typography>
                <Stack direction="row" spacing={1} alignItems="center">
                    <Typography sx={{ color: '#64748B', fontSize: '12px' }}>Less</Typography>
                    {COLORS.map((color) => (
                        <Box key={color} sx={{ width: 18, height: 18, borderRadius: '5px', bgcolor: color, border: '1px solid #E2E8F0' }} />
                    ))}
                    <Typography sx={{ color: '#64748B', fontSize: '12px' }}>More</Typography>
                </Stack>
            </Stack>
        </Box>
    );
}
