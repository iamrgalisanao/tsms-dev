import React from 'react';
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend
} from 'chart.js';
import { Doughnut } from 'react-chartjs-2';

ChartJS.register(ArcElement, Tooltip, Legend);

const RevenueByTerminalChart = ({ data, loading }) => {
    if (loading) return (
        <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center justify-center h-64">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        </div>
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
        <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex flex-col h-full">
            <h3 className="text-sm font-bold text-gray-900 mb-6 uppercase tracking-widest">Revenue by Terminal</h3>
            <div className="flex-1 relative min-h-[250px]">
                <Doughnut data={chartData} options={options} />
            </div>
        </div>
    );
};

export default RevenueByTerminalChart;
