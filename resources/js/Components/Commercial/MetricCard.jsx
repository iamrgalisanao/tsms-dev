import React from 'react';
import { Card, CardContent, Typography, Box, Avatar } from '@mui/material';

const MetricCard = ({ title, value, icon: Icon, color = 'primary.main', subtitle }) => {
    const RenderIcon = () => {
        if (!Icon) return <span className="material-symbols-outlined text-2xl">analytics</span>;
        if (typeof Icon === 'string') return <span className="material-symbols-outlined text-2xl">{Icon}</span>;
        return <Icon sx={{ fontSize: 24 }} />;
    };

    return (
        <div className="glass-card rounded-2xl p-6 border border-white/40 shadow-xl transition-all hover:-translate-y-1 hover:shadow-2xl group">
            <div className="flex justify-between items-start mb-4">
                <div className="size-12 rounded-xl pitx-gradient flex items-center justify-center text-white shadow-lg shadow-primary/20">
                    <RenderIcon />
                </div>
                {subtitle && (
                    <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">{subtitle}</span>
                )}
            </div>

            <div className="space-y-1">
                <p className="text-xs font-bold text-slate-500 uppercase tracking-widest opacity-70">
                    {title}
                </p>
                <h3 className="text-3xl font-black text-slate-900 tracking-tight group-hover:pitx-text-gradient transition-all">
                    {value}
                </h3>
            </div>
        </div>
    );
};

export default MetricCard;
