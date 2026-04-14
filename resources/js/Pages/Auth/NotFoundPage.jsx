import React from 'react';
import { Link } from 'react-router-dom';
import MapIcon from '@mui/icons-material/Map';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';

const NotFoundPage = () => {
    return (
        <div className="min-h-screen flex items-center justify-center bg-slate-50 p-6">
            <div className="max-w-md w-full glass-card rounded-[32px] p-12 border border-white/40 shadow-2xl text-center space-y-8 animate-in zoom-in duration-500">
                <div className="size-32 rounded-full bg-blue-50 flex items-center justify-center mx-auto text-blue-600 shadow-inner group transition-transform hover:scale-110">
                    <MapIcon sx={{ fontSize: 64 }} />
                </div>
                
                <div className="space-y-3">
                    <div className="flex flex-col items-center">
                        <span className="text-6xl font-black text-slate-200 tabular-nums">404</span>
                        <h2 className="text-3xl font-black text-slate-900 tracking-tight -mt-4">Node Not Found</h2>
                    </div>
                    <p className="text-slate-500 font-medium">
                        The coordinate you requested does not exist in the current grid. It may have been decommissioned or moved.
                    </p>
                </div>

                <div className="pt-4 border-t border-slate-100 space-y-4">
                    <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">
                        Navigation Error Detected
                    </p>
                    
                    <Link
                        to="/dashboard"
                        className="flex items-center justify-center gap-2 pitx-gradient text-white px-8 py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-primary/20 transition-all hover:scale-105 active:scale-95"
                    >
                        <ArrowBackIcon sx={{ fontSize: 16 }} />
                        Back to Command Center
                    </Link>
                </div>

                <div className="text-[10px] text-slate-400 font-medium tracking-tighter">
                    REFERENCE ID: {Math.random().toString(36).substring(7).toUpperCase()} • SECURE OPS
                </div>
            </div>
        </div>
    );
};

export default NotFoundPage;
