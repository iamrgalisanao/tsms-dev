import React from 'react';
import { useNavigate } from 'react-router-dom';

const REPORTS = [
    {
        title: 'Hourly Sales',
        path: '/commercial/reports/hourly',
        icon: 'schedule',
        color: '#df1160',
        bg: 'rgba(223,17,96,0.08)',
        description: 'Track sales and transaction volume grouped by hour for any selected day.',
    },
    {
        title: 'Daily Sales',
        path: '/commercial/reports/daily',
        icon: 'today',
        color: '#2563eb',
        bg: 'rgba(37,99,235,0.08)',
        description: 'Aggregate summary of all transactions for a single business day.',
    },
    {
        title: 'Weekly Sales',
        path: '/commercial/reports/weekly',
        icon: 'date_range',
        color: '#7c3aed',
        bg: 'rgba(124,58,237,0.08)',
        description: 'Performance trends across a full 7-day period with day-by-day breakdown.',
    },
    {
        title: 'Weekday Sales',
        path: '/commercial/reports/weekday',
        icon: 'event_note',
        color: '#ea580c',
        bg: 'rgba(234,88,12,0.08)',
        description: 'Monday to Friday operational performance — excludes weekend traffic.',
    },
    {
        title: 'Weekend Sales',
        path: '/commercial/reports/weekend',
        icon: 'weekend',
        color: '#0891b2',
        bg: 'rgba(8,145,178,0.08)',
        description: 'Peak-period report focused on Saturday and Sunday transaction flow.',
    },
    {
        title: 'Monthly Sales',
        path: '/commercial/reports/monthly',
        icon: 'calendar_month',
        color: '#059669',
        bg: 'rgba(5,150,105,0.08)',
        description: 'Strategic view of monthly growth and tenant contributions.',
    },
    {
        title: 'Yearly Sales',
        path: '/commercial/reports/yearly',
        icon: 'calendar_today',
        color: '#4f46e5',
        bg: 'rgba(79,70,229,0.08)',
        description: 'Comprehensive annual performance overview with month-level drill-down.',
    },
];

const ReportCard = ({ report, onClick }) => (
    <button
        type="button"
        onClick={onClick}
        style={{
            all: 'unset',
            display: 'block',
            cursor: 'pointer',
            background: 'white',
            borderRadius: 20,
            border: '1.5px solid #e2e8f0',
            padding: '28px 28px 24px',
            transition: 'all 0.2s ease',
            textAlign: 'left',
            width: '100%',
            boxSizing: 'border-box',
            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
            position: 'relative',
        }}
        onMouseEnter={e => {
            e.currentTarget.style.boxShadow = `0 8px 32px ${report.color}22, 0 2px 8px rgba(0,0,0,0.06)`;
            e.currentTarget.style.borderColor = report.color + '55';
            e.currentTarget.style.transform = 'translateY(-3px)';
        }}
        onMouseLeave={e => {
            e.currentTarget.style.boxShadow = '0 1px 3px rgba(0,0,0,0.04)';
            e.currentTarget.style.borderColor = '#e2e8f0';
            e.currentTarget.style.transform = 'translateY(0)';
        }}
    >
        {/* Icon */}
        <div style={{
            width: 52, height: 52,
            borderRadius: 14,
            background: report.bg,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            marginBottom: 18,
        }}>
            <span className="material-symbols-outlined" style={{ fontSize: 28, color: report.color }}>{report.icon}</span>
        </div>

        {/* Title */}
        <h3 style={{ margin: '0 0 8px', fontSize: 17, fontWeight: 800, color: '#0f172a', letterSpacing: '-0.01em' }}>
            {report.title}
        </h3>

        {/* Description */}
        <p style={{ margin: '0 0 24px', fontSize: 13, color: '#64748b', lineHeight: 1.6, fontWeight: 500 }}>
            {report.description}
        </p>

        {/* Footer CTA */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em', color: '#94a3b8' }}>
                View Report
            </span>
            <div style={{
                width: 32, height: 32,
                borderRadius: '50%',
                background: report.bg,
                display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>
                <span className="material-symbols-outlined" style={{ fontSize: 18, color: report.color }}>arrow_forward</span>
            </div>
        </div>

        {/* Accent line at top */}
        <div style={{
            position: 'absolute', top: 0, left: 28, right: 28, height: 3,
            borderRadius: '0 0 3px 3px',
            background: report.color,
            opacity: 0.15,
        }} />
    </button>
);

const ReportsOverviewPage = () => {
    const navigate = useNavigate();

    return (
        <div style={{ paddingBottom: 40 }}>
            {/* Page Header */}
            <div style={{ marginBottom: 36, display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: 16 }}>
                <div>
                    <h1 style={{ margin: '0 0 8px', fontSize: 32, fontWeight: 900, color: '#0f172a', letterSpacing: '-0.025em', lineHeight: 1 }}>
                        Reports Hub
                    </h1>
                    <p style={{ margin: 0, color: '#64748b', fontWeight: 500, fontSize: 15 }}>
                        Select an analytical engine to explore your commercial ecosystem.
                    </p>
                </div>
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 8,
                    padding: '8px 16px',
                    background: '#f1fdf7',
                    border: '1px solid #bbf7d0',
                    borderRadius: 999,
                    flexShrink: 0,
                }}>
                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#22c55e', display: 'inline-block' }} />
                    <span style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#15803d' }}>
                        Real-time Stream Active
                    </span>
                </div>
            </div>

            {/* Quick-access strip */}
            <div style={{
                background: 'linear-gradient(135deg, #1e3a5f 0%, #df1160 100%)',
                borderRadius: 20,
                padding: '24px 32px',
                marginBottom: 32,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 16,
            }}>
                <div>
                    <p style={{ margin: '0 0 4px', fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.12em', color: 'rgba(255,255,255,0.6)' }}>
                        Most Used
                    </p>
                    <h2 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'white', letterSpacing: '-0.01em' }}>
                        Daily &amp; Hourly Reports
                    </h2>
                    <p style={{ margin: '6px 0 0', fontSize: 13, color: 'rgba(255,255,255,0.7)', fontWeight: 500 }}>
                        Quick access to the most frequently used report engines.
                    </p>
                </div>
                <div style={{ display: 'flex', gap: 10 }}>
                    <button
                        onClick={() => navigate('/commercial/reports/daily')}
                        style={{
                            background: 'rgba(255,255,255,0.15)',
                            border: '1.5px solid rgba(255,255,255,0.25)',
                            color: 'white',
                            borderRadius: 12,
                            padding: '10px 20px',
                            fontWeight: 800,
                            fontSize: 13,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            backdropFilter: 'blur(8px)',
                        }}
                    >
                        <span className="material-symbols-outlined" style={{ fontSize: 18 }}>today</span>
                        Daily
                    </button>
                    <button
                        onClick={() => navigate('/commercial/reports/hourly')}
                        style={{
                            background: 'white',
                            border: 'none',
                            color: '#1e3a5f',
                            borderRadius: 12,
                            padding: '10px 20px',
                            fontWeight: 800,
                            fontSize: 13,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                        }}
                    >
                        <span className="material-symbols-outlined" style={{ fontSize: 18 }}>schedule</span>
                        Hourly
                    </button>
                </div>
            </div>

            {/* Report Cards Grid */}
            <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                gap: 20,
            }}>
                {REPORTS.map(report => (
                    <ReportCard
                        key={report.path}
                        report={report}
                        onClick={() => navigate(report.path)}
                    />
                ))}

                {/* System Info card */}
                <div style={{
                    background: '#0f172a',
                    borderRadius: 20,
                    padding: '28px 28px 24px',
                    color: 'white',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    position: 'relative',
                    overflow: 'hidden',
                    minHeight: 220,
                }}>
                    <div style={{ position: 'relative', zIndex: 1 }}>
                        <span className="material-symbols-outlined" style={{ fontSize: 36, color: '#df1160', display: 'block', marginBottom: 14 }}>verified</span>
                        <h3 style={{ margin: '0 0 8px', fontSize: 18, fontWeight: 900, fontStyle: 'italic' }}>Precision Analytics</h3>
                        <p style={{ margin: 0, fontSize: 13, color: 'rgba(255,255,255,0.55)', lineHeight: 1.6, fontWeight: 500 }}>
                            100% data integrity across all PITX commercial transactions.
                        </p>
                    </div>

                    <div style={{ marginTop: 24, paddingTop: 20, borderTop: '1px solid rgba(255,255,255,0.08)', display: 'flex', alignItems: 'center', gap: 10, position: 'relative', zIndex: 1 }}>
                        <span className="material-symbols-outlined" style={{ fontSize: 16, color: '#22c55e' }}>circle</span>
                        <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em', color: 'rgba(255,255,255,0.4)' }}>
                            System Status: Stable
                        </span>
                    </div>

                    {/* Decorative icon — constrained inside the card */}
                    <span className="material-symbols-outlined" style={{
                        position: 'absolute',
                        bottom: -20, right: -20,
                        fontSize: 130,
                        color: 'rgba(255,255,255,0.04)',
                        pointerEvents: 'none',
                        userSelect: 'none',
                    }}>monitoring</span>
                </div>
            </div>
        </div>
    );
};

export default ReportsOverviewPage;
