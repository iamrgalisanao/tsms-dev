import React from 'react';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';

const AlertBanner = ({ type = 'warning', title, message, children }) => {
    // Styling states mapping
    const states = {
        success: {
            bg: 'bg-emerald-50/70 border-emerald-200 text-emerald-950',
            iconBg: 'bg-emerald-100 text-emerald-800',
            icon: <CheckCircleIcon />,
            role: 'status'
        },
        warning: {
            bg: 'bg-amber-50/70 border-amber-200 text-amber-950',
            iconBg: 'bg-amber-100 text-amber-800',
            icon: <LockOutlinedIcon />,
            role: 'status'
        },
        error: {
            bg: 'bg-rose-50/70 border-rose-200 text-rose-950',
            iconBg: 'bg-rose-100 text-[#b91c1c]',
            icon: <ErrorOutlineIcon />,
            role: 'alert'
        }
    };

    const current = states[type] || states.warning;

    return (
        <div 
            className={`p-5 border rounded-2xl flex items-start gap-4 relative overflow-hidden shadow-sm transition-all ${current.bg}`}
            role={current.role}
        >
            {/* Background design accents */}
            <div className="absolute right-0 top-0 opacity-5 pointer-events-none select-none font-bold uppercase" aria-hidden="true">
                {type === 'warning' && <LockOutlinedIcon style={{ fontSize: 120 }} />}
                {type === 'success' && <CheckCircleIcon style={{ fontSize: 120 }} />}
                {type === 'error' && <ErrorOutlineIcon style={{ fontSize: 120 }} />}
            </div>
            
            {/* Left Icon */}
            <div className={`p-2.5 rounded-lg shrink-0 mt-0.5 ${current.iconBg}`} aria-hidden="true">
                {current.icon}
            </div>

            {/* Content area */}
            <div className="space-y-1 relative z-10">
                {title && <h4 className="text-sm font-bold leading-snug">{title}</h4>}
                <div className="text-xs opacity-90 leading-relaxed max-w-4xl">
                    {message || children}
                </div>
            </div>
        </div>
    );
};

export default AlertBanner;
