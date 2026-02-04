import React from 'react';
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend
} from 'chart.js';
import { Card, CardContent, Typography, Box, CircularProgress } from '@mui/material';
import { Doughnut } from 'react-chartjs-2';

ChartJS.register(ArcElement, Tooltip, Legend);

const RevenueByTerminalChart = ({ data, loading }) => {
    if (loading) return (
        <Card sx={{ height: '100%', borderRadius: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 250 }}>
            <CircularProgress size={32} color="primary" />
        </Card>
    );

    const chartData = {
        labels: data?.map(d => d.trade_name) || ['None'],
        datasets: [{
            data: data?.map(d => d.total_sales) || [100],
            backgroundColor: [
                'rgba(37, 99, 235, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(245, 158, 11, 0.8)',
                'rgba(239, 68, 68, 0.8)',
                'rgba(139, 92, 246, 0.8)',
            ],
            borderColor: '#ffffff',
            borderWidth: 2,
            hoverOffset: 12,
        }]
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 20,
                    font: { size: 10, weight: 'bold' }
                }
            },
            tooltip: {
                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                titleColor: '#111827',
                bodyColor: '#4B5563',
                borderColor: '#E5E7EB',
                borderWidth: 1,
                padding: 12,
                callbacks: {
                    label: function (context) {
                        const val = context.raw;
                        return ` ${new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val)}`;
                    }
                }
            }
        },
        cutout: '70%',
    };

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
                    Revenue by Terminal
                </Typography>
                <Box sx={{ flex: 1, minHeight: 250, position: 'relative' }}>
                    <Doughnut data={chartData} options={options} />
                </Box>
            </CardContent>
        </Card>
    );
};

export default RevenueByTerminalChart;
