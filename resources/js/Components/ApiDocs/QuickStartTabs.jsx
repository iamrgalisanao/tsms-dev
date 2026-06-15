import React, { useState, useRef } from 'react';
import ArticleIcon from '@mui/icons-material/Article';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import SearchIcon from '@mui/icons-material/Search';

const QuickStartTabs = ({ scrollToSection }) => {
    const [activeTab, setActiveTab] = useState('quickstart'); // 'quickstart' or 'reference'
    const tabListRef = useRef(null);

    const steps = [
        {
            num: '1',
            title: 'Configure Credentials',
            desc: 'Acquire a staging bearer token and configure terminal boundaries in TSMS Staging environment.',
            target: 'access',
            icon: <LockOutlinedIcon style={{ fontSize: 16 }} />
        },
        {
            num: '2',
            title: 'Conform to Schema',
            desc: 'Ensure your JSON payload mirrors the root envelope structure and transaction requirements.',
            target: 'payload-guidelines',
            icon: <FactCheckIcon style={{ fontSize: 16 }} />
        },
        {
            num: '3',
            title: 'Check Cryptographic Hash',
            desc: 'Calculate the payload_checksum and validate it via the sandbox validator without side effects.',
            target: 'sandbox',
            icon: <FactCheckIcon style={{ fontSize: 16 }} />
        },
        {
            num: '4',
            title: 'Query Ingestion status',
            desc: 'Poll the status API using your transactional UUID to track queuing and processing states.',
            target: 'status',
            icon: <SearchIcon style={{ fontSize: 16 }} />
        }
    ];

    const handleKeyDown = (e) => {
        if (e.key === 'ArrowLeft') {
            setActiveTab('quickstart');
        } else if (e.key === 'ArrowRight') {
            setActiveTab('reference');
        }
    };

    return (
        <div className="space-y-6">
            {/* Tabs List Navigation */}
            <div 
                ref={tabListRef}
                className="flex border-b border-[#c6c6cd]/50"
                role="tablist"
                aria-label="Documentation mode"
                onKeyDown={handleKeyDown}
            >
                <button
                    role="tab"
                    id="tab-quickstart"
                    aria-selected={activeTab === 'quickstart'}
                    aria-controls="panel-quickstart"
                    tabIndex={activeTab === 'quickstart' ? 0 : -1}
                    onClick={() => setActiveTab('quickstart')}
                    className={`py-3 px-6 text-sm font-bold uppercase tracking-wider border-b-2 transition-all flex items-center gap-2 focus:outline-none ${
                        activeTab === 'quickstart'
                            ? 'border-blue-600 text-blue-600'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    }`}
                >
                    <FactCheckIcon style={{ fontSize: 16 }} />
                    Quick Start Guide
                </button>
                <button
                    role="tab"
                    id="tab-reference"
                    aria-selected={activeTab === 'reference'}
                    aria-controls="panel-reference"
                    tabIndex={activeTab === 'reference' ? 0 : -1}
                    onClick={() => setActiveTab('reference')}
                    className={`py-3 px-6 text-sm font-bold uppercase tracking-wider border-b-2 transition-all flex items-center gap-2 focus:outline-none ${
                        activeTab === 'reference'
                            ? 'border-blue-600 text-blue-600'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    }`}
                >
                    <ArticleIcon style={{ fontSize: 16 }} />
                    Deep Dive Reference
                </button>
            </div>

            {/* Tab panels */}
            <div
                id="panel-quickstart"
                role="tabpanel"
                aria-labelledby="tab-quickstart"
                hidden={activeTab !== 'quickstart'}
                className="focus:outline-none"
                tabIndex={0}
            >
                <div className="bg-white border border-[#c6c6cd] rounded-2xl p-6 shadow-sm">
                    <div className="max-w-xl mb-6">
                        <h3 className="text-base font-bold text-[#0b1c30]">Getting Started Quick Timeline</h3>
                        <p className="text-xs text-slate-400 mt-1">
                            Follow these four streamlined steps to run your first end-to-end sandbox validation workflow.
                        </p>
                    </div>

                    {/* Timeline stepper */}
                    <div className="relative pl-6 border-l-2 border-slate-100 space-y-8">
                        {steps.map((step, idx) => (
                            <div key={step.num} className="relative">
                                {/* Dot step marker */}
                                <div 
                                    className="absolute -left-11 top-0.5 w-7 h-7 rounded-full bg-blue-50 border-2 border-blue-600 flex items-center justify-center text-xs font-black text-blue-700 font-mono shadow-sm"
                                    aria-hidden="true"
                                >
                                    {step.num}
                                </div>

                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div className="space-y-1">
                                        <h4 className="text-sm font-black text-slate-900 flex items-center gap-1.5">
                                            <span className="text-slate-400">{step.icon}</span>
                                            {step.title}
                                        </h4>
                                        <p className="text-xs text-slate-500 leading-relaxed max-w-xl">
                                            {step.desc}
                                        </p>
                                    </div>
                                    <button
                                        onClick={() => scrollToSection(step.target)}
                                        className="text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 hover:bg-blue-100/60 px-3 py-1.5 rounded-lg transition-colors border border-blue-100 flex items-center gap-1 shrink-0 self-start sm:self-center"
                                    >
                                        Jump to Section &rarr;
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div
                id="panel-reference"
                role="tabpanel"
                aria-labelledby="tab-reference"
                hidden={activeTab !== 'reference'}
                className="focus:outline-none"
                tabIndex={0}
            >
                <div className="bg-[#eff4ff]/60 border border-blue-100 rounded-2xl p-5 flex items-start gap-3">
                    <ArticleIcon className="text-blue-600 shrink-0 mt-0.5" style={{ fontSize: 20 }} />
                    <p className="text-xs text-[#0b1c30] leading-relaxed">
                        <strong>Deep Dive Mode Enabled:</strong> The complete technical specifications, schemas, endpoints, response matrices, and SDK parameters are displayed below. Scroll or use the left navigation menu to view sections.
                    </p>
                </div>
            </div>
        </div>
    );
};

export default QuickStartTabs;
