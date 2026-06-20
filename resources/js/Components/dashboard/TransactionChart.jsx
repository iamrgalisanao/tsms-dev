import React, { useMemo } from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Card, CardContent, Typography, Box, Stack, CircularProgress, useTheme } from '@mui/material';
import { Line } from 'react-chartjs-2';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const TransactionChart = ({ data, loading, inline = false, height = 450 }) => {
    const theme = useTheme();

    const options = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: !inline,
                position: 'top',
                align: 'end',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 20,
                    font: { size: 11, weight: '700', family: 'Inter' },
                    color: '#64748B'
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                titleColor: '#FFFFFF',
                titleFont: { size: 13, weight: 'bold', family: 'Inter' },
                bodyColor: '#E2E8F0',
                bodyFont: { size: 12, family: 'Inter' },
                borderColor: 'rgba(255, 255, 255, 0.1)',
                borderWidth: 1,
                padding: 12,
                boxPadding: 6,
                usePointStyle: true,
                cornerRadius: 8,
                callbacks: {
                    label: function (context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        if (context.parsed.y !== null) {
                            label += context.dataset.yAxisID === 'y'
                                ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed.y)
                                : context.parsed.y;
                        }
                        return label;
                    }
                }
            },
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Sales (₱)',
                    font: { size: 10, weight: 'bold', family: 'Inter' },
                    color: '#64748B'
                },
                grid: {
                    drawBorder: false,
                    color: 'rgba(29, 67, 155, 0.05)',
                    borderDash: [4, 4]
                },
                ticks: {
                    font: { size: 9, family: 'Inter', weight: '500' },
                    color: '#94A3B8',
                    callback: (value) => '₱' + new Intl.NumberFormat().format(value)
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Volume (Count)',
                    font: { size: 10, weight: 'bold', family: 'Inter' },
                    color: '#64748B'
                },
                grid: {
                    drawOnChartArea: false,
                },
                ticks: {
                    font: { size: 9, family: 'Inter', weight: '500' },
                    color: '#94A3B8'
                }
            },
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    font: { size: 9, family: 'Inter', weight: '500' },
                    color: '#94A3B8',
                    maxRotation: 0,
                    maxTicksLimit: 8
                }
            },
        },
        interaction: {
            mode: 'nearest',
            axis: 'x',
            intersect: false,
        },
    }), [inline]);

    const displayData = useMemo(() => {
        const labels = (data?.labels || []).map((l) => {
            if (typeof l === 'number' || !isNaN(l)) {
                return `${String(l).padStart(2, '0')}:00`;
            }
            return l;
        });

        return {
            labels,
            sales: data?.sales || [],
            previous_sales: data?.previous_sales || [],
            volume: data?.volume || []
        };
    }, [data]);

    const hasData = useMemo(
        () => (displayData.sales?.length || 0) > 0 || (displayData.volume?.length || 0) > 0,
        [displayData]
    );

    const chartData = useMemo(() => ({
        labels: displayData.labels,
        datasets: [
            {
                label: 'Current Sales (₱)',
                data: displayData.sales,
                borderColor: '#1D439B',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(29, 67, 155, 0.16)');
                    gradient.addColorStop(1, 'rgba(29, 67, 155, 0.01)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#1D439B',
                yAxisID: 'y',
            },
            {
                label: 'Prev. Period Sales (₱)',
                data: displayData.previous_sales,
                borderColor: '#94A3B8',
                borderDash: [5, 4],
                fill: false,
                tension: 0.4,
                pointRadius: 0,
                yAxisID: 'y',
            },
            {
                label: 'Transaction Volume (Units)',
                data: displayData.volume,
                borderColor: '#EB342E',
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#EB342E',
                yAxisID: 'y1',
            },
        ],
    }), [displayData]);

    const renderChartContent = () => (
        <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
            {inline && (
                <Stack direction="row" spacing={2} sx={{ mb: 2, justifyContent: 'flex-start' }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '11px', fontWeight: '600', color: '#64748B' }}>
                        <Box sx={{ width: 16, height: 2, bgcolor: '#1A56DB', mr: 1 }} />
                        Current Period
                    </Box>
                    <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '11px', fontWeight: '600', color: '#64748B' }}>
                        <Box sx={{ width: 16, height: 2, borderBottom: '2px dashed #94A3B8', mr: 1 }} />
                        Prior Period
                    </Box>
                    <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '11px', fontWeight: '600', color: '#64748B' }}>
                        <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: '#EB342E', mr: 1 }} />
                        Volume
                    </Box>
                </Stack>
            )}
            <Box sx={{ flex: 1, minHeight: 0 }}>
                {hasData ? (
                    <Line options={options} data={chartData} />
                ) : (
                    <Box sx={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                        <Typography variant="body2" sx={{ color: 'text.secondary' }}>
                            No sales analytics available for the selected range.
                        </Typography>
                    </Box>
                )}
            </Box>
        </Box>
    );

    if (loading) {
        const loadingContent = (
            <Box sx={{ height: '100%', minHeight: inline ? 260 : height, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1.5 }}>
                <CircularProgress sx={{ color: '#e11d2d' }} />
                <Typography variant="caption" sx={{ color: '#64748b', fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                    Loading chart details...
                </Typography>
            </Box>
        );

        if (inline) {
            return (
                <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', p: 1, bgcolor: '#F4F6FA', borderRadius: '10px' }}>
                    {loadingContent}
                </Box>
            );
        }

        return (
            <Card sx={{ height: height, borderRadius: '32px' }}>
                {loadingContent}
            </Card>
        );
    }

    if (inline) {
        return (
            <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', p: 1, bgcolor: '#F4F6FA', borderRadius: '10px' }}>
                {renderChartContent()}
            </Box>
        );
    }

    return (
        <Card sx={{
            height: height,
            transition: 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)',
            '&:hover': {
                transform: 'translateY(-2px)',
                boxShadow: theme.custom?.shadows?.cardHover || '0 12px 40px rgba(29, 67, 155, 0.08)',
            }
        }}>
            <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column', p: '20px !important' }}>
                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 3 }}>
                    <Typography variant="h6" sx={{ fontWeight: 800, color: '#0F172A', letterSpacing: '-0.02em' }}>
                        Transaction Analytics
                    </Typography>
                    <Stack direction="row" spacing={2.5}>
                        <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '11px', fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                            <Box sx={{ width: 10, height: 10, bgcolor: 'primary.main', borderRadius: '50%', mr: 1, boxShadow: '0 2px 6px rgba(29, 67, 155, 0.3)' }} />
                            Sales
                        </Box>
                        <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '11px', fontWeight: 800, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                            <Box sx={{ width: 10, height: 10, bgcolor: 'secondary.main', borderRadius: '50%', mr: 1, boxShadow: '0 2px 6px rgba(235, 52, 46, 0.3)' }} />
                            Volume
                        </Box>
                    </Stack>
                </Stack>
                {renderChartContent()}
            </CardContent>
        </Card>
    );
};

export default TransactionChart;
