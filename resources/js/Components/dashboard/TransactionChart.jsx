import React from 'react';
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

const TransactionChart = ({ data, loading }) => {
    if (loading) {
        return (
            <div className="bg-white p-6 rounded-xl shadow-sm h-96 flex items-center justify-center border border-gray-100">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
        );
    }

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
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
                    color: 'rgba(229, 231, 245, 0.5)',
                },
                ticks: {
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
            },
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    font: { size: 11 }
                }
            },
        },
        interaction: {
            mode: 'nearest',
            axis: 'x',
            intersect: false,
        },
    };

    const chartData = {
        labels: data?.labels || [],
        datasets: [
            {
                label: 'Current Sales',
                data: data?.sales || [],
                borderColor: 'rgb(37, 99, 235)',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                yAxisID: 'y',
            },
            {
                label: 'Prev. Period Sales',
                data: data?.previous_sales || [],
                borderColor: 'rgba(37, 99, 235, 0.3)',
                borderDash: [5, 5],
                fill: false,
                tension: 0.4,
                pointRadius: 0,
                yAxisID: 'y',
            },
            {
                label: 'Volume',
                data: data?.volume || [],
                borderColor: 'rgb(16, 185, 129)',
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                yAxisID: 'y1',
            },
        ],
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow-sm h-[450px] border border-gray-100">
            <div className="flex justify-between items-center mb-6">
                <h3 className="text-lg font-bold text-gray-900">Transaction Analytics</h3>
                <div className="flex space-x-2 text-xs">
                    <span className="flex items-center"><span className="w-3 h-3 bg-blue-600 rounded-full mr-1"></span> Sales</span>
                    <span className="flex items-center"><span className="w-3 h-3 bg-green-500 rounded-full mr-1"></span> Volume</span>
                </div>
            </div>
            <div className="h-80">
                <Line options={options} data={chartData} />
            </div>
        </div>
    );
};

export default TransactionChart;
