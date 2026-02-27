import React from 'react';
import {
    Box,
    Typography,
    Button,
    Grid,
    TextField,
    MenuItem,
    IconButton,
    Paper
} from '@mui/material';
import {
    Refresh as RefreshIcon,
    FileDownload as FileDownloadIcon,
    Search as SearchIcon
} from '@mui/icons-material';

const ReportHeader = ({
    title,
    dateFrom,
    dateTo,
    tenantId,
    tenants = [],
    onDateFromChange,
    onDateToChange,
    onTenantChange,
    onLoadReport,
    onExportExcel,
    loading = false,
    showFilters = true
}) => {
    return (
        <div className="glass-card rounded-2xl p-6 mb-8 border border-white/40 shadow-xl">
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                <div>
                    <h2 className="text-2xl font-black text-slate-900 tracking-tight leading-none mb-2">
                        {title}
                    </h2>
                    <p className="text-xs font-bold text-slate-500 uppercase tracking-widest italic opacity-70">
                        Analytical Engine v2.0
                    </p>
                </div>

                {showFilters && (
                    <div className="flex flex-wrap items-center gap-3 lg:justify-end">
                        <div className="flex items-center gap-2 bg-white/50 p-1.5 rounded-xl border border-white/60">
                            <input
                                type="date"
                                className="bg-transparent border-none text-xs font-bold text-slate-600 focus:ring-0 outline-none px-2"
                                value={dateFrom}
                                onChange={(e) => onDateFromChange(e.target.value)}
                                title="Date From"
                            />
                            <span className="text-slate-300">|</span>
                            <input
                                type="date"
                                className="bg-transparent border-none text-xs font-bold text-slate-600 focus:ring-0 outline-none px-2"
                                value={dateTo}
                                onChange={(e) => onDateToChange(e.target.value)}
                                title="Date To"
                            />
                        </div>

                        <select
                            value={tenantId || ''}
                            onChange={(e) => onTenantChange(e.target.value)}
                            className="bg-white/50 border border-white/60 rounded-xl px-4 py-2 text-xs font-bold text-slate-600 focus:ring-1 focus:ring-primary outline-none shadow-sm min-w-[200px]"
                        >
                            <option value="">All Tenants</option>
                            {tenants.map((tenant) => (
                                <option key={tenant.id} value={tenant.id}>
                                    {tenant.trade_name}
                                </option>
                            ))}
                        </select>

                        <div className="flex items-center gap-2">
                            <button
                                onClick={onLoadReport}
                                disabled={loading}
                                className="pitx-gradient text-white px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:opacity-90 disabled:opacity-50 transition-all flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-sm">refresh</span>
                                {loading ? 'Loading...' : 'Load'}
                            </button>

                            <button
                                onClick={onExportExcel}
                                disabled={loading}
                                className="bg-white border border-slate-200 text-slate-700 px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-slate-50 shadow-sm disabled:opacity-50 transition-all flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-sm text-emerald-500">download</span>
                                Export
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default ReportHeader;
