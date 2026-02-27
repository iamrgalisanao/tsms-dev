import React from 'react';
import { useNavigate } from 'react-router-dom';
import {
    Grid,
    Card,
    CardContent,
    Typography,
    Box,
    Avatar,
    Button,
    Container
} from '@mui/material';
import {
    Schedule as HourIcon,
    Today as DayIcon,
    DateRange as WeekIcon,
    EventNote as WeekdayIcon,
    Hotel as WeekendIcon,
    CalendarMonth as MonthIcon,
    CalendarToday as YearIcon,
    TrendingUp as ReportsIcon
} from '@mui/icons-material';

const reportTypes = [
    {
        title: 'Hourly Sales',
        path: '/commercial/reports/hourly',
        icon: 'schedule',
        color: '#df1160',
        description: 'Vitals for today\'s performance. Track sales and volume per hour.'
    },
    {
        title: 'Daily Sales',
        path: '/commercial/reports/daily',
        icon: 'today',
        color: '#2563eb',
        description: 'Aggregate summary of transactions for a single business day.'
    },
    {
        title: 'Weekly Sales',
        path: '/commercial/reports/weekly',
        icon: 'date_range',
        color: '#7c3aed',
        description: 'Analysis of performance trends across a full 7-day cycle.'
    },
    {
        title: 'Weekday Sales',
        path: '/commercial/reports/weekday',
        icon: 'event_note',
        color: '#ea580c',
        description: 'Deep dive into Monday-Friday operational performance.'
    },
    {
        title: 'Weekend Sales',
        path: '/commercial/reports/weekend',
        icon: 'hotel',
        color: '#0891b2',
        description: 'Peak period reporting for Saturday and Sunday traffic.'
    },
    {
        title: 'Monthly Sales',
        path: '/commercial/reports/monthly',
        icon: 'calendar_month',
        color: '#059669',
        description: 'Strategic review of monthly growth and tenant contributions.'
    },
    {
        title: 'Yearly Sales',
        path: '/commercial/reports/yearly',
        icon: 'calendar_today',
        color: '#4f46e5',
        description: 'Comprehensive annual performance and target tracking.'
    }
];

const ReportsOverviewPage = () => {
    const navigate = useNavigate();

    return (
        <div className="p-8 max-w-[1600px] mx-auto">
            <div className="flex items-center justify-between mb-12">
                <div>
                    <h1 className="text-4xl font-black text-slate-900 tracking-tight leading-none mb-3">
                        Reports Hub
                    </h1>
                    <p className="text-slate-500 font-medium">
                        Select an analytical engine to explore your commercial ecosystem.
                    </p>
                </div>

                <div className="flex items-center gap-2 px-4 py-2 bg-slate-100 rounded-full">
                    <span className="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Real-time Stream Active</span>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                {reportTypes.map((report) => (
                    <div
                        key={report.path}
                        onClick={() => navigate(report.path)}
                        className="glass-card rounded-3xl p-8 cursor-pointer transition-all hover:-translate-y-2 hover:shadow-2xl hover:border-primary/20 group relative overflow-hidden"
                    >
                        <div className="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                            <span className="material-symbols-outlined text-9xl">{report.icon}</span>
                        </div>

                        <div className="size-14 rounded-2xl pitx-gradient flex items-center justify-center text-white mb-6 shadow-lg shadow-primary/20 group-hover:scale-110 transition-transform">
                            <span className="material-symbols-outlined text-3xl">{report.icon}</span>
                        </div>

                        <h3 className="text-xl font-black text-slate-900 mb-2 group-hover:pitx-text-gradient transition-all">
                            {report.title}
                        </h3>

                        <p className="text-sm font-medium text-slate-500 leading-relaxed mb-8">
                            {report.description}
                        </p>

                        <div className="flex items-center justify-between">
                            <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest group-hover:text-primary transition-colors">
                                View Engine
                            </span>
                            <div className="size-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 group-hover:pitx-gradient group-hover:text-white transition-all">
                                <span className="material-symbols-outlined text-sm">arrow_forward</span>
                            </div>
                        </div>
                    </div>
                ))}

                {/* Info Card */}
                <div className="bg-slate-900 rounded-3xl p-8 flex flex-col justify-between text-white premium-shadow relative overflow-hidden">
                    <div className="z-10">
                        <span className="material-symbols-outlined text-4xl text-primary mb-6">verified</span>
                        <h3 className="text-xl font-black mb-2 italic">Precision Analytics</h3>
                        <p className="text-slate-400 text-sm font-medium leading-relaxed">
                            Our reporting suite ensures 100% data integrity for all PITX commercial transactions.
                        </p>
                    </div>

                    <div className="absolute -bottom-10 -right-10 opacity-20">
                        <span className="material-symbols-outlined text-[150px] rotate-12">monitoring</span>
                    </div>

                    <div className="mt-8 pt-8 border-t border-white/10 flex items-center gap-4">
                        <div className="size-8 rounded-lg bg-white/10 flex items-center justify-center">
                            <span className="material-symbols-outlined text-xs">info</span>
                        </div>
                        <p className="text-[10px] font-bold text-slate-500 uppercase tracking-widest italic leading-none">
                            System Status: Stable
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ReportsOverviewPage;
