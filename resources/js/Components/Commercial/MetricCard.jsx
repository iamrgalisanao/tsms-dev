import React from 'react';
import { Card, CardContent, Typography, Box, Avatar } from '@mui/material';

const MetricCard = ({ title, value, icon: Icon, color = 'primary.main', subtitle }) => {
    let iconElement;
    if (!Icon) {
        iconElement = <span className="material-symbols-outlined text-2xl">analytics</span>;
    } else if (typeof Icon === 'string') {
        iconElement = <span className="material-symbols-outlined text-2xl">{Icon}</span>;
    } else if (React.isValidElement(Icon)) {
        iconElement = React.cloneElement(Icon, { sx: { fontSize: 24, ...Icon.props.sx } });
    } else {
        iconElement = <Icon sx={{ fontSize: 24 }} />;
    }

    return (
        <div className="glass-card rounded-2xl p-6 border border-white/40 shadow-xl transition-all hover:-translate-y-1 hover:shadow-2xl group">
            <div className="flex justify-between items-start mb-4">
                <div className="size-12 rounded-xl bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center text-white shadow-lg shadow-blue-900/20">
                    {iconElement}
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
