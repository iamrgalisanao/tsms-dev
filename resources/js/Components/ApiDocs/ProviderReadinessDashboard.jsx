import React, { useMemo } from 'react';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import AlertBanner from './AlertBanner';
import FactCheckIcon from '@mui/icons-material/FactCheck';

const ProviderReadinessDashboard = ({ completedSteps = {}, scrollToSection }) => {
    const steps = [
        { key: 'credentials', label: 'Credentials Setup', target: 'access', desc: 'Authorized credentials & staging token validation' },
        { key: 'schema', label: 'Schema Conformity', target: 'payload-guidelines', desc: 'Ingestion envelope structure conformity' },
        { key: 'payload', label: 'Payload Checksum', target: 'payload-guidelines', desc: 'Transaction SHA-256 cryptographic check' },
        { key: 'workflow', label: 'Workflow Testing', target: 'sandbox', desc: 'Mock ingestion flow in Sandbox validator' },
        { key: 'approval', label: 'Production Go-Live', target: 'rate-limits', desc: 'Rate limits alignment & helpdesk request' }
    ];

    const stats = useMemo(() => {
        const total = steps.length;
        const completed = steps.filter((step) => completedSteps[step.key]).length;
        const percentage = Math.round((completed / total) * 100);
        
        // Find first incomplete step as the "Next Step" recommendation
        const nextStep = steps.find((step) => !completedSteps[step.key]);

        return { total, completed, percentage, nextStep };
    }, [completedSteps]);

    // SVG Circular calculations
    const radius = 46;
    const strokeWidth = 8;
    const circumference = 2 * Math.PI * radius;
    const strokeDashoffset = circumference - (circumference * stats.percentage) / 100;

    return (
        <div className="bg-white border border-[#c6c6cd] rounded-2xl p-6 shadow-sm flex flex-col md:flex-row gap-6 items-center md:items-stretch justify-between">
            {/* Left Section: Circular dynamic progress */}
            <div className="flex flex-col items-center justify-center text-center px-4 md:border-r border-[#c6c6cd]/50 shrink-0">
                <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 mb-3 block">
                    Readiness Score
                </span>
                
                <div className="relative w-32 h-32 flex items-center justify-center">
                    <svg className="w-full h-full transform -rotate-90">
                        {/* Background track circle */}
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            className="text-slate-100"
                            strokeWidth={strokeWidth}
                            stroke="currentColor"
                            fill="transparent"
                        />
                        {/* Dynamic progress track circle */}
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            className="text-blue-600 transition-all duration-500 ease-out"
                            strokeWidth={strokeWidth}
                            strokeDasharray={circumference}
                            strokeDashoffset={strokeDashoffset}
                            strokeLinecap="round"
                            stroke="currentColor"
                            fill="transparent"
                        />
                    </svg>
                    
                    {/* Inner Text */}
                    <div className="absolute flex flex-col items-center justify-center select-none">
                        <span className="text-3xl font-black text-slate-900 leading-none">{stats.percentage}%</span>
                        <span className="text-[10px] font-bold text-slate-400 uppercase mt-1">Complete</span>
                    </div>
                </div>

                <div className="mt-4 text-xs font-semibold text-slate-500">
                    {stats.completed} of {stats.total} Tasks Passed
                </div>
            </div>

            {/* Middle Section: Onboarding Tasks Checklist */}
            <div className="flex-1 space-y-4">
                <div>
                    <h3 className="text-sm font-black uppercase tracking-wider text-[#0b1c30]">
                        Onboarding Requirements Checklist
                    </h3>
                    <p className="text-xs text-slate-400 mt-0.5">
                        Ensure all checks are green before submitting your live production gateway request.
                    </p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    {steps.map((step) => {
                        const isDone = completedSteps[step.key];
                        return (
                            <button
                                key={step.key}
                                onClick={() => scrollToSection(step.target)}
                                className={`flex items-start gap-3 p-2.5 rounded-xl border text-left transition-all focus:outline-none focus:ring-2 focus:ring-blue-500/20 ${
                                    isDone 
                                        ? 'border-emerald-100 bg-emerald-50/20 hover:bg-emerald-50/40 text-slate-800' 
                                        : 'border-slate-100 bg-slate-50/30 hover:bg-slate-50 text-slate-600'
                                }`}
                            >
                                <div className="shrink-0 mt-0.5" aria-hidden="true">
                                    {isDone ? (
                                        <CheckCircleIcon className="text-green-600" style={{ fontSize: 18 }} />
                                    ) : (
                                        <div className="w-4.5 h-4.5 rounded-full border-2 border-slate-300" />
                                    )}
                                </div>
                                <div>
                                    <div className={`text-xs font-bold ${isDone ? 'line-through text-slate-400' : 'text-slate-900'}`}>
                                        {step.label}
                                    </div>
                                    <div className="text-[10px] text-slate-400 truncate max-w-[190px]">
                                        {step.desc}
                                    </div>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Right Section: Action Guide CTA */}
            <div className="w-full md:w-64 flex flex-col justify-between p-4 bg-blue-50/50 border border-blue-100 rounded-xl shrink-0">
                <div className="space-y-1.5">
                    <span className="text-[9px] font-black uppercase text-blue-600 tracking-wider">
                        Next Suggested Step
                    </span>
                    {stats.nextStep ? (
                        <>
                            <h4 className="text-xs font-bold text-slate-900">{stats.nextStep.label}</h4>
                            <p className="text-[11px] text-slate-500 leading-relaxed">
                                {stats.nextStep.desc}. Let's jump to this section to complete onboarding.
                            </p>
                        </>
                    ) : (
                        <>
                            <h4 className="text-xs font-bold text-emerald-950">Onboarding Verified!</h4>
                            <p className="text-[11px] text-emerald-800/80 leading-relaxed">
                                All staging test validation requirements have passed. You are ready to Go Live!
                            </p>
                        </>
                    )}
                </div>

                <button
                    onClick={() => scrollToSection(stats.nextStep ? stats.nextStep.target : 'rate-limits')}
                    className="w-full mt-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm hover:scale-[1.02] active:scale-[0.98]"
                >
                    <FactCheckIcon style={{ fontSize: 14 }} />
                    {stats.nextStep ? 'Start Task' : 'Request Onboarding'}
                </button>
            </div>
        </div>
    );
};

export default ProviderReadinessDashboard;
