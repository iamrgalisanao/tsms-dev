import React from 'react';
import { useNavigate } from 'react-router-dom';
import {
    Schedule as HourlyIcon,
    Today as DailyIcon,
    DateRange as WeeklyIcon,
    EventNote as WeekdayIcon,
    Weekend as WeekendIcon,
    CalendarMonth as MonthlyIcon,
    CalendarToday as YearlyIcon,
    Verified as VerifiedIcon,
    Circle as CircleIcon,
    ArrowForward as ArrowIcon,
    FiberManualRecord as DotIcon,
} from '@mui/icons-material';

// ── PITX Brand Colors ────────────────────────────────────────────────────────
const BLUE = '#1D439B';
const RED = '#EB342E';
const NAVY = '#0f2044';   // darker card / info block

// ── Report definitions ───────────────────────────────────────────────────────
const REPORTS = [
    {
        title: 'Hourly Sales',
        path: '/commercial/reports/hourly',
        Icon: HourlyIcon,
        accent: RED,
        bg: 'rgba(235,52,46,0.08)',
        description: 'Track sales and transaction volume grouped by hour for any selected day.',
    },
    {
        title: 'Daily Sales',
        path: '/commercial/reports/daily',
        Icon: DailyIcon,
        accent: BLUE,
        bg: 'rgba(29,67,155,0.08)',
        description: 'Aggregate summary of all transactions for a single business day.',
    },
    {
        title: 'Weekly Sales',
        path: '/commercial/reports/weekly',
        Icon: WeeklyIcon,
        accent: '#7c3aed',
        bg: 'rgba(124,58,237,0.08)',
        description: 'Performance trends across a full 7-day period with day-by-day breakdown.',
    },
    {
        title: 'Weekday Sales',
        path: '/commercial/reports/weekday',
        Icon: WeekdayIcon,
        accent: '#ea580c',
        bg: 'rgba(234,88,12,0.08)',
        description: 'Monday–Friday operational performance, excluding weekend traffic.',
    },
    {
        title: 'Weekend Sales',
        path: '/commercial/reports/weekend',
        Icon: WeekendIcon,
        accent: '#0891b2',
        bg: 'rgba(8,145,178,0.08)',
        description: 'Peak-period report focused on Saturday and Sunday transaction flow.',
    },
    {
        title: 'Monthly Sales',
        path: '/commercial/reports/monthly',
        Icon: MonthlyIcon,
        accent: '#059669',
        bg: 'rgba(5,150,105,0.08)',
        description: 'Strategic view of monthly growth and tenant sales contributions.',
    },
    {
        title: 'Yearly Sales',
        path: '/commercial/reports/yearly',
        Icon: YearlyIcon,
        accent: '#4f46e5',
        bg: 'rgba(79,70,229,0.08)',
        description: 'Comprehensive annual performance overview with month-level drill-down.',
    },
];

// ── Sub-components ───────────────────────────────────────────────────────────
const ReportCard = ({ report, onClick }) => {
    const { title, Icon, accent, bg, description } = report;
    const [hovered, setHovered] = React.useState(false);

    return (
        <button
            type="button"
            onClick={onClick}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            style={{
                all: 'unset',
                display: 'flex',
                flexDirection: 'column',
                cursor: 'pointer',
                background: 'white',
                borderRadius: 18,
                border: `1.5px solid ${hovered ? accent + '55' : '#e2e8f0'}`,
                padding: '24px 24px 20px',
                width: '100%',
                boxSizing: 'border-box',
                boxShadow: hovered
                    ? `0 8px 28px ${accent}22, 0 2px 8px rgba(0,0,0,0.04)`
                    : '0 1px 4px rgba(0,0,0,0.05)',
                transform: hovered ? 'translateY(-3px)' : 'translateY(0)',
                transition: 'all 0.18s ease',
                textAlign: 'left',
                position: 'relative',
                overflow: 'hidden',
            }}
        >
            {/* Top accent bar */}
            <div style={{
                position: 'absolute', top: 0, left: 0, right: 0, height: 3,
                background: accent,
                opacity: hovered ? 1 : 0.25,
                transition: 'opacity 0.18s ease',
                borderRadius: '18px 18px 0 0',
            }} />

            {/* Icon badge */}
            <div style={{
                width: 48, height: 48,
                borderRadius: 13,
                background: bg,
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                marginBottom: 16,
                flexShrink: 0,
            }}>
                <Icon style={{ fontSize: 26, color: accent }} />
            </div>

            {/* Title */}
            <h3 style={{
                margin: '0 0 8px',
                fontSize: 16,
                fontWeight: 800,
                color: hovered ? accent : '#0f172a',
                letterSpacing: '-0.01em',
                transition: 'color 0.18s ease',
                lineHeight: 1.2,
            }}>
                {title}
            </h3>

            {/* Description */}
            <p style={{
                margin: '0 0 20px',
                fontSize: 12.5,
                color: '#64748b',
                lineHeight: 1.6,
                fontWeight: 500,
                flexGrow: 1,
            }}>
                {description}
            </p>

            {/* Footer */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span style={{
                    fontSize: 10, fontWeight: 800,
                    textTransform: 'uppercase', letterSpacing: '0.1em',
                    color: hovered ? accent : '#94a3b8',
                    transition: 'color 0.18s ease',
                }}>
                    View Report
                </span>
                <div style={{
                    width: 30, height: 30,
                    borderRadius: '50%',
                    background: hovered ? accent : bg,
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    transition: 'background 0.18s ease',
                }}>
                    <ArrowIcon style={{ fontSize: 16, color: hovered ? 'white' : accent }} />
                </div>
            </div>
        </button>
    );
};

// ── Main Page ────────────────────────────────────────────────────────────────
const ReportsOverviewPage = () => {
    const navigate = useNavigate();

    return (
        <div style={{ paddingBottom: 48 }}>

            {/* ── Page Header ─────────────────────────────────────────── */}
            <div style={{
                display: 'flex', alignItems: 'flex-start',
                justifyContent: 'space-between',
                flexWrap: 'wrap', gap: 16,
                marginBottom: 32,
            }}>
                <div>
                    <h1 style={{
                        margin: '0 0 6px',
                        fontSize: 30, fontWeight: 900, color: '#0f172a',
                        letterSpacing: '-0.025em', lineHeight: 1,
                    }}>
                        Reports Hub
                    </h1>
                    <p style={{ margin: 0, color: '#64748b', fontWeight: 500, fontSize: 14 }}>
                        Select an analytical engine to explore your commercial ecosystem.
                    </p>
                </div>

                {/* Live badge */}
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 7,
                    padding: '7px 14px',
                    background: '#f0fdf4',
                    border: '1px solid #bbf7d0',
                    borderRadius: 999,
                    flexShrink: 0,
                    alignSelf: 'flex-start',
                }}>
                    <DotIcon style={{ fontSize: 10, color: '#22c55e' }} />
                    <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.09em', color: '#15803d' }}>
                        Real-time Stream Active
                    </span>
                </div>
            </div>

            {/* ── Quick-access banner ─────────────────────────────────── */}
            <div style={{
                background: `linear-gradient(135deg, ${NAVY} 0%, ${BLUE} 50%, ${RED} 100%)`,
                borderRadius: 18,
                padding: '22px 28px',
                marginBottom: 28,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 16,
            }}>
                <div>
                    <p style={{ margin: '0 0 4px', fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.12em', color: 'rgba(255,255,255,0.55)' }}>
                        Most Used
                    </p>
                    <h2 style={{ margin: 0, fontSize: 20, fontWeight: 900, color: 'white', letterSpacing: '-0.01em' }}>
                        Daily &amp; Hourly Reports
                    </h2>
                    <p style={{ margin: '5px 0 0', fontSize: 13, color: 'rgba(255,255,255,0.65)', fontWeight: 500 }}>
                        Quick access to the most frequently used report engines.
                    </p>
                </div>
                <div style={{ display: 'flex', gap: 10 }}>
                    <button
                        onClick={() => navigate('/commercial/reports/daily')}
                        style={{
                            background: 'rgba(255,255,255,0.15)',
                            border: '1.5px solid rgba(255,255,255,0.3)',
                            color: 'white',
                            borderRadius: 12,
                            padding: '9px 18px',
                            fontWeight: 800,
                            fontSize: 13,
                            cursor: 'pointer',
                            display: 'flex', alignItems: 'center', gap: 7,
                        }}
                    >
                        <DailyIcon style={{ fontSize: 17 }} />
                        Daily
                    </button>
                    <button
                        onClick={() => navigate('/commercial/reports/hourly')}
                        style={{
                            background: 'white',
                            border: 'none',
                            color: NAVY,
                            borderRadius: 12,
                            padding: '9px 18px',
                            fontWeight: 800,
                            fontSize: 13,
                            cursor: 'pointer',
                            display: 'flex', alignItems: 'center', gap: 7,
                        }}
                    >
                        <HourlyIcon style={{ fontSize: 17 }} />
                        Hourly
                    </button>
                </div>
            </div>

            {/* ── Report Cards Grid ───────────────────────────────────── */}
            {/* Section label */}
            <p style={{ margin: '0 0 16px', fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.1em', color: '#94a3b8' }}>
                All Report Engines
            </p>
            <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fill, minmax(230px, 1fr))',
                gap: 16,
            }}>
                {REPORTS.map(report => (
                    <ReportCard
                        key={report.path}
                        report={report}
                        onClick={() => navigate(report.path)}
                    />
                ))}

                {/* System Info card — same height as report cards */}
                <div style={{
                    background: NAVY,
                    borderRadius: 18,
                    padding: '24px 24px 20px',
                    color: 'white',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    position: 'relative',
                    overflow: 'hidden',
                }}>
                    {/* Content */}
                    <div style={{ position: 'relative', zIndex: 1 }}>
                        <div style={{ marginBottom: 14 }}>
                            <VerifiedIcon style={{ fontSize: 34, color: RED }} />
                        </div>
                        <h3 style={{ margin: '0 0 8px', fontSize: 16, fontWeight: 900, fontStyle: 'italic', lineHeight: 1.2 }}>
                            Precision Analytics
                        </h3>
                        <p style={{ margin: 0, fontSize: 12.5, color: 'rgba(255,255,255,0.55)', lineHeight: 1.6, fontWeight: 500 }}>
                            100% data integrity across all PITX commercial transactions.
                        </p>
                    </div>

                    {/* Footer */}
                    <div style={{
                        marginTop: 24,
                        paddingTop: 16,
                        borderTop: '1px solid rgba(255,255,255,0.1)',
                        display: 'flex', alignItems: 'center', gap: 8,
                        position: 'relative', zIndex: 1,
                    }}>
                        <CircleIcon style={{ fontSize: 10, color: '#22c55e' }} />
                        <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.09em', color: 'rgba(255,255,255,0.35)' }}>
                            System Status: Stable
                        </span>
                    </div>

                    {/* Decorative BG element — contained */}
                    <VerifiedIcon style={{
                        position: 'absolute',
                        bottom: -24, right: -20,
                        fontSize: 110,
                        color: 'rgba(255,255,255,0.04)',
                        pointerEvents: 'none',
                    }} />
                </div>
            </div>
        </div>
    );
};

export default ReportsOverviewPage;
