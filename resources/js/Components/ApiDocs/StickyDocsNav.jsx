import React, { useEffect, useState } from 'react';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ArticleIcon from '@mui/icons-material/Article';
import SearchIcon from '@mui/icons-material/Search';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import TerminalIcon from '@mui/icons-material/Terminal';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import InfoIcon from '@mui/icons-material/Info';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';

const getIcon = (id, size = 18) => {
    switch (id) {
        case 'access': return <LockOutlinedIcon style={{ fontSize: size }} />;
        case 'payload-guidelines': return <FactCheckIcon style={{ fontSize: size }} />;
        case 'openapi': return <ArticleIcon style={{ fontSize: size }} />;
        case 'status': return <SearchIcon style={{ fontSize: size }} />;
        case 'errors': return <ErrorOutlineIcon style={{ fontSize: size }} />;
        case 'rate-limits': return <ShieldOutlinedIcon style={{ fontSize: size }} />;
        case 'monitoring': return <TerminalIcon style={{ fontSize: size }} />;
        case 'sandbox': return <FactCheckIcon style={{ fontSize: size }} />;
        case 'downloads': return <InfoIcon style={{ fontSize: size }} />;
        default: return <HelpOutlineIcon style={{ fontSize: size }} />;
    }
};

const StickyDocsNav = ({
    activeSection,
    scrollToSection,
    completedSteps = {},
    resourceLinks = []
}) => {
    const navSections = [
        {
            title: '01. Configure Provider',
            items: [
                { id: 'access', label: 'Access Setup', stepKey: 'credentials' }
            ]
        },
        {
            title: '02. Validate Data',
            items: [
                { id: 'payload-guidelines', label: 'Payload Guidelines', stepKey: 'schema' },
                { id: 'errors', label: 'Staging Errors', stepKey: 'schema' }
            ]
        },
        {
            title: '03. Test Workflow',
            items: [
                { id: 'openapi', label: 'API Explorer', stepKey: 'payload' },
                { id: 'status', label: 'Status Lookup', stepKey: 'workflow' },
                { id: 'monitoring', label: 'Activity Monitoring', stepKey: 'workflow' },
                { id: 'sandbox', label: 'Payload Sandbox', stepKey: 'workflow' }
            ]
        },
        {
            title: '04. Go Live',
            items: [
                { id: 'rate-limits', label: 'Rate Limits', stepKey: 'approval' },
                { id: 'downloads', label: 'Resource Files', stepKey: 'approval' }
            ]
        }
    ];

    return (
        <aside 
            className="hidden md:flex flex-col w-64 bg-[#eff4ff] border-r border-[#c6c6cd] shrink-0 h-full overflow-y-auto"
            aria-label="Documentation Navigation"
        >
            <div className="p-5 flex-1 flex flex-col gap-6">
                {/* Onboarding Checklist Status */}
                <div className="bg-white/80 backdrop-blur-sm border border-[#c6c6cd]/60 rounded-xl p-3.5 shadow-sm">
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2.5">
                        Readiness Tracker
                    </span>
                    <ul className="space-y-2 text-xs">
                        {[
                            { key: 'credentials', label: 'Credentials' },
                            { key: 'schema', label: 'Schema Conformity' },
                            { key: 'payload', label: 'Payload Checksum' },
                            { key: 'workflow', label: 'Workflow Testing' },
                            { key: 'approval', label: 'Production Go-Live' }
                        ].map((step) => {
                            const isDone = completedSteps[step.key];
                            return (
                                <li key={step.key} className="flex items-center gap-2 text-slate-600 font-medium">
                                    {isDone ? (
                                        <CheckCircleIcon className="text-green-600 shrink-0" style={{ fontSize: 15 }} aria-label="Completed" />
                                    ) : (
                                        <div className="w-3.5 h-3.5 rounded-full border-2 border-slate-300 shrink-0" aria-label="Pending" />
                                    )}
                                    <span className={isDone ? 'line-through text-slate-400 font-normal' : ''}>{step.label}</span>
                                </li>
                            );
                        })}
                    </ul>
                </div>

                {/* Section Navigation */}
                <div>
                    <h5 className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">
                        Documentation
                    </h5>
                    <nav className="space-y-4" role="tablist" aria-label="Sections">
                        {navSections.map((group) => (
                            <div key={group.title} className="space-y-1">
                                <span className="text-[9px] font-black uppercase tracking-wider text-slate-400/80 block px-2.5 mb-1.5">
                                    {group.title}
                                </span>
                                {group.items.map((item) => {
                                    const isActive = activeSection === item.id;
                                    return (
                                        <button
                                            key={item.id}
                                            role="tab"
                                            aria-selected={isActive}
                                            aria-controls={item.id}
                                            id={`nav-link-${item.id}`}
                                            onClick={() => scrollToSection(item.id)}
                                            className={`flex items-center gap-2.5 px-3 py-1.5 rounded-lg transition-all text-left w-full text-xs font-semibold ${
                                                isActive
                                                    ? 'bg-blue-600 text-white shadow-sm hover:bg-blue-700'
                                                    : 'text-slate-600 hover:text-[#0b1c30] hover:bg-[#dce9ff]'
                                            }`}
                                        >
                                            <span className="shrink-0">{getIcon(item.id, 16)}</span>
                                            <span className="truncate flex-1">{item.label}</span>
                                            {isActive && (
                                                <span className="w-1.5 h-1.5 rounded-full bg-white animate-pulse" />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>
                </div>

                {/* Resource Files */}
                {resourceLinks.length > 0 && (
                    <div>
                        <h5 className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2.5">
                            Resources
                        </h5>
                        <nav className="space-y-1">
                            {resourceLinks.map(([label, href]) => (
                                <a
                                    key={href}
                                    href={href}
                                    className="flex items-center gap-2.5 text-slate-600 hover:text-blue-600 px-3 py-1.5 hover:bg-[#dce9ff]/50 rounded-lg transition-all text-xs font-semibold"
                                >
                                    <ArticleIcon style={{ fontSize: 15 }} className="text-slate-400 shrink-0" />
                                    <span className="truncate">{label}</span>
                                </a>
                            ))}
                        </nav>
                    </div>
                )}
            </div>

            {/* Sidebar Footer info */}
            <div className="p-5 border-t border-[#c6c6cd] bg-[#eff4ff]">
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded bg-[#101828] text-white flex items-center justify-center font-bold text-xs" aria-hidden="true">
                        v1
                    </div>
                    <div>
                        <p className="text-xs font-bold text-[#0b1c30]">API Version 1.0.0</p>
                        <p className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Production Stable</p>
                    </div>
                </div>
            </div>
        </aside>
    );
};

export default StickyDocsNav;
