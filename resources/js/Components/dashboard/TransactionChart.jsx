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
import { Card, CardContent, Typography, Box, Stack, CircularProgress } from '@mui/material';
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

const TransactionChart = ({ data, loading, inline = false }) => {
    if (loading) {
        return (
            <Card sx={{ height: 450, borderRadius: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <CircularProgress color="primary" />
            </Card>
        );
    }

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
                    font: { size: 12, weight: '600' }
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                titleColor: '#111827',
                titleFont: { size: 14, weight: 'bold' },
                bodyColor: '#4B5563',
                borderColor: '#E5E7EB',
                borderWidth: 1,
                padding: 12,
                boxPadding: 6,
                usePointStyle: true,
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
                    font: { size: 11, weight: 'bold' }
                },
                grid: {
                    drawBorder: false,
                    color: 'rgba(0, 0, 0, 0.04)',
                },
                ticks: {
                    font: { size: 10, family: 'Inter' },
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
                    font: { size: 11, weight: 'bold' }
                },
                grid: {
                    drawOnChartArea: false,
                },
                ticks: {
                    font: { size: 10, family: 'Inter' },
                    color: '#94A3B8'
                }
            },
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    font: { size: 10, family: 'Inter' },
                    color: '#94A3B8',
                    maxRotation: 45,
                    minRotation: 45,
                    maxTicksLimit: 12
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
                borderColor: '#1A56DB',
                backgroundColor: 'rgba(26, 86, 219, 0.04)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                yAxisID: 'y',
            },
            {
                label: 'Prev. Period Sales (₱)',
                data: displayData.previous_sales,
                borderColor: '#94A3B8',
                borderDash: [4, 3],
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
                pointRadius: 4,
                pointHoverRadius: 6,
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

    if (inline) {
        return (
            <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', p: 1, bgcolor: '#F4F6FA', borderRadius: '10px' }}>
                {renderChartContent()}
            </Box>
        );
    }

    return (
        <Card sx={{ height: 450, borderRadius: '32px', p: 2 }}>
            <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 4 }}>
                    <Typography variant="h6" sx={{ fontWeight: 900, color: 'primary.main' }}>
                        Transaction Analytics
                    </Typography>
                    <Stack direction="row" spacing={2}>
                        <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '12px', fontWeight: 'bold', color: 'grey.600' }}>
                            <Box sx={{ width: 12, height: 12, bgcolor: 'primary.main', borderRadius: '50%', mr: 1 }} />
                            Sales
                        </Box>
                        <Box sx={{ display: 'flex', alignItems: 'center', fontSize: '12px', fontWeight: 'bold', color: 'grey.600' }}>
                            <Box sx={{ width: 12, height: 12, bgcolor: 'secondary.main', borderRadius: '50%', mr: 1 }} />
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
