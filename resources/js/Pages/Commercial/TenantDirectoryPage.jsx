import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import {
    Storefront as StorefrontIcon,
    Group as GroupIcon,
    SquareFoot as SquareFootIcon,
    EventSeat as EventSeatIcon,
    Search as SearchIcon,
    Store as StoreIcon,
    GridView as GridViewIcon,
    ViewList as ViewListIcon,
    ChevronLeft as ChevLeft,
    ChevronRight as ChevRight,
    TrendingUp as TrendingUpIcon,
    CheckCircle as CheckCircleIcon,
} from '@mui/icons-material';
import '../../../css/TenantDirectory.css';

// ── PITX brand ────────────────────────────────────────────────────────────────
const BLUE = '#1D439B';
const RED = '#EB342E';

// ── Status pill ───────────────────────────────────────────────────────────────
const StatusPill = ({ status }) => {
    const active = (status || 'active').toLowerCase() === 'active';
    return (
        <span style={{
            display: 'inline-flex', alignItems: 'center', gap: 5,
            padding: '2px 9px',
            borderRadius: 999,
            background: active ? '#f0fdf4' : '#fffbeb',
            border: `1px solid ${active ? '#bbf7d0' : '#fde68a'}`,
            fontSize: 10, fontWeight: 800,
            textTransform: 'uppercase', letterSpacing: '0.07em',
            color: active ? '#15803d' : '#b45309',
        }}>
            <span style={{
                width: 6, height: 6, borderRadius: '50%',
                background: active ? '#22c55e' : '#f59e0b',
                display: 'inline-block',
            }} />
            {status || 'Active'}
        </span>
    );
};

// ── Category badge ─────────────────────────────────────────────────────────────
const catColor = (cat) => {
    const c = (cat || '').toLowerCase();
    if (c === 'food') return { bg: '#fff7ed', color: '#ea580c', border: '#fed7aa' };
    if (c === 'retail') return { bg: '#eff6ff', color: BLUE, border: '#bfdbfe' };
    if (c === 'service') return { bg: '#f5f3ff', color: '#7c3aed', border: '#ddd6fe' };
    return { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' };
};

const CategoryBadge = ({ cat }) => {
    const { bg, color, border } = catColor(cat);
    return (
        <span style={{
            display: 'inline-block',
            padding: '2px 9px',
            borderRadius: 999,
            background: bg, color, border: `1px solid ${border}`,
            fontSize: 10, fontWeight: 800,
            textTransform: 'uppercase', letterSpacing: '0.07em',
        }}>
            {cat || 'Retail'}
        </span>
    );
};

// ── Mini sparkline ─────────────────────────────────────────────────────────────
const Sparkline = ({ color = BLUE }) => (
    <div style={{ display: 'flex', alignItems: 'flex-end', gap: 2, height: 24 }}>
        {[20, 35, 25, 50, 75, 40, 60].map((h, i) => (
            <div key={i} style={{
                width: 4, borderRadius: 2,
                height: `${h}%`,
                background: i >= 4 ? color : '#e2e8f0',
                opacity: i >= 4 ? 1 : 0.6,
            }} />
        ))}
    </div>
);

// ── Stat Card ─────────────────────────────────────────────────────────────────
const StatCard = ({ icon: Icon, iconColor, label, value }) => (
    <div style={{
        background: 'white', borderRadius: 14,
        border: '1px solid #e2e8f0',
        padding: '16px 20px',
        display: 'flex', alignItems: 'center', gap: 16,
        boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
        flex: 1, minWidth: 160,
    }}>
        <div style={{
            width: 44, height: 44, borderRadius: 12,
            background: iconColor + '15',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            flexShrink: 0,
        }}>
            <Icon style={{ fontSize: 22, color: iconColor }} />
        </div>
        <div>
            <p style={{ margin: '0 0 2px', fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.07em' }}>{label}</p>
            <p style={{ margin: 0, fontSize: 22, fontWeight: 900, color: '#0f172a', letterSpacing: '-0.02em' }}>{value}</p>
        </div>
    </div>
);

// ── Main Component ─────────────────────────────────────────────────────────────
const TenantDirectoryPage = () => {
    const navigate = useNavigate();
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [viewMode, setViewMode] = useState('grid');
    const [categoryFilter, setCategoryFilter] = useState('All');
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 12;

    useEffect(() => { fetchTenants(); }, []);

    const fetchTenants = async () => {
        try {
            setLoading(true);
            const response = await axios.get('/commercial/reports/tenants', { headers: { Accept: 'application/json' } });
            setTenants(response.data);
        } catch (err) {
            setError('Failed to load tenants. Please try again later.');
        } finally {
            setLoading(false);
        }
    };

    const filteredTenants = tenants.filter(t => {
        const matchSearch = (t.trade_name || '').toLowerCase().includes(searchTerm.toLowerCase())
            || (t.customer_code || '').toLowerCase().includes(searchTerm.toLowerCase());
        const matchCat = categoryFilter === 'All' || (t.category || '').toLowerCase() === categoryFilter.toLowerCase();
        return matchSearch && matchCat;
    });

    const totalPages = Math.ceil(filteredTenants.length / itemsPerPage);
    const paginatedTenants = filteredTenants.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

    useEffect(() => { setCurrentPage(1); }, [searchTerm, categoryFilter]);

    // ── Loading / Error ──────────────────────────────────────────────────────
    if (loading) return (
        <div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 24 }}>
                {[1, 2, 3, 4, 5, 6].map(i => (
                    <div key={i} className="glass" style={{ height: 260, borderRadius: 24, animation: 'pulse 1.5s infinite' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', padding: 24 }}>
                            <div style={{ width: 60, height: 20, background: '#e2e8f0', borderRadius: 6 }} />
                            <div style={{ width: 60, height: 20, background: '#e2e8f0', borderRadius: 6 }} />
                        </div>
                        <div style={{ padding: '0 24px 24px' }}>
                            <div style={{ width: '80%', height: 28, background: '#f1f5f9', borderRadius: 8, marginBottom: 8 }} />
                            <div style={{ width: '40%', height: 16, background: '#f1f5f9', borderRadius: 8, marginBottom: 24 }} />
                            <div style={{ display: 'flex', gap: 12 }}>
                                <div style={{ flex: 1, height: 40, background: '#f8fafc', borderRadius: 12 }} />
                                <div style={{ flex: 1, height: 40, background: '#f8fafc', borderRadius: 12 }} />
                            </div>
                        </div>
                    </div>
                ))}
            </div>
            <style>{`
                @keyframes pulse { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
            `}</style>
        </div>
    );

    if (error) return (
        <div style={{ padding: 24, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 12, color: '#b91c1c' }}>{error}</div>
    );

    return (
        <div style={{ paddingBottom: 48 }}>

            {/* ── Page Header ──────────────────────────────────────────── */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 28, flexWrap: 'wrap', gap: 12 }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
                        <StorefrontIcon style={{ fontSize: 28, color: BLUE }} />
                        <h1 style={{ margin: 0, fontSize: 26, fontWeight: 900, color: '#0f172a', letterSpacing: '-0.02em' }}>
                            Tenant Directory
                        </h1>
                    </div>
                    <p style={{ margin: 0, fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8' }}>
                        Commercial Portal
                    </p>
                </div>
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 7,
                    padding: '6px 14px',
                    background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 999,
                }}>
                    <TrendingUpIcon style={{ fontSize: 14, color: BLUE }} />
                    <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748b' }}>Live Data View</span>
                </div>
            </div>

            {/* ── Stat Row ─────────────────────────────────────────────── */}
            <div style={{ display: 'flex', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
                <StatCard icon={GroupIcon} iconColor={BLUE} label="Total Tenants" value={tenants.length} />
                <StatCard icon={SquareFootIcon} iconColor="#0891b2" label="Total Area" value="15,400 sqm" />
                <StatCard icon={EventSeatIcon} iconColor="#059669" label="Current Occupancy" value="94.2%" />
            </div>

            {/* ── Controls ─────────────────────────────────────────────── */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 24, flexWrap: 'wrap' }}>
                {/* Search */}
                <div style={{ flex: 1, minWidth: 240, position: 'relative' }}>
                    <SearchIcon style={{
                        position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)',
                        fontSize: 18, color: '#94a3b8', pointerEvents: 'none',
                    }} />
                    <input
                        type="text"
                        placeholder="Search tenants by name, code, or location..."
                        value={searchTerm}
                        onChange={e => setSearchTerm(e.target.value)}
                        style={{
                            width: '100%', boxSizing: 'border-box',
                            paddingLeft: 44, paddingRight: 16, paddingTop: 10, paddingBottom: 10,
                            border: '1.5px solid #e2e8f0', borderRadius: 12,
                            fontSize: 13, fontWeight: 500, color: '#0f172a',
                            background: 'white', outline: 'none',
                            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                        }}
                        onFocus={e => { e.target.style.borderColor = BLUE; }}
                        onBlur={e => { e.target.style.borderColor = '#e2e8f0'; }}
                    />
                </div>

                {/* Category filter */}
                <select
                    value={categoryFilter}
                    onChange={e => setCategoryFilter(e.target.value)}
                    style={{
                        padding: '10px 14px',
                        border: '1.5px solid #e2e8f0', borderRadius: 12,
                        fontSize: 13, fontWeight: 600, color: '#475569',
                        background: 'white', outline: 'none', cursor: 'pointer',
                        boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                    }}
                >
                    <option value="All">All Categories</option>
                    <option value="Food">Food &amp; Beverage</option>
                    <option value="Retail">Retail</option>
                    <option value="Service">Service</option>
                    <option value="Logistics">Logistics</option>
                </select>

                {/* View toggle */}
                <div style={{
                    display: 'flex', background: 'white',
                    border: '1.5px solid #e2e8f0', borderRadius: 12, padding: 4,
                    boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                }}>
                    {[
                        { mode: 'grid', Icon: GridViewIcon, label: 'Grid' },
                        { mode: 'list', Icon: ViewListIcon, label: 'List' },
                    ].map(({ mode, Icon, label }) => (
                        <button key={mode} onClick={() => setViewMode(mode)} style={{
                            display: 'flex', alignItems: 'center', gap: 6,
                            padding: '6px 14px', borderRadius: 9,
                            border: 'none', cursor: 'pointer',
                            fontWeight: 800, fontSize: 12,
                            background: viewMode === mode ? BLUE : 'transparent',
                            color: viewMode === mode ? 'white' : '#64748b',
                            transition: 'all 0.15s ease',
                        }}>
                            <Icon style={{ fontSize: 16 }} />
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {/* ── Count & Results header ────────────────────────────────── */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                <p style={{ margin: 0, fontSize: 12, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                    {filteredTenants.length} tenant{filteredTenants.length !== 1 ? 's' : ''} found
                </p>
            </div>

            {/* ── Grid View ────────────────────────────────────────────── */}
            {viewMode === 'grid' && (
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))',
                    gap: 16,
                    marginBottom: 24,
                }}>
                    {paginatedTenants.map(tenant => (
                        <div key={tenant.id} style={{
                            background: 'white', borderRadius: 16,
                            border: '1.5px solid #e2e8f0',
                            padding: '20px 20px 16px',
                            boxShadow: '0 1px 4px rgba(0,0,0,0.04)',
                            display: 'flex', flexDirection: 'column', gap: 0,
                        }}>
                            {/* Top: Logo + meta */}
                            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', marginBottom: 14 }}>
                                <div style={{
                                    width: 48, height: 48, borderRadius: 12, flexShrink: 0,
                                    background: '#f1f5f9', border: '1px solid #e2e8f0',
                                    display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden',
                                }}>
                                    {tenant.logo_url
                                        ? <img src={tenant.logo_url} alt={tenant.trade_name} style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                                        : <StoreIcon style={{ fontSize: 22, color: '#cbd5e1' }} />
                                    }
                                </div>
                                <div style={{ flex: 1, minWidth: 0 }}>
                                    <p style={{ margin: '0 0 3px', fontWeight: 800, fontSize: 14, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                        {tenant.trade_name}
                                    </p>
                                    <p style={{ margin: '0 0 6px', fontSize: 10, fontFamily: 'monospace', color: '#94a3b8', textTransform: 'uppercase' }}>
                                        {tenant.customer_code}
                                    </p>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 5, flexWrap: 'wrap' }}>
                                        <CategoryBadge cat={tenant.category} />
                                        <StatusPill status={tenant.status} />
                                    </div>
                                </div>
                            </div>

                            {/* Sales strip */}
                            <div style={{
                                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                padding: '10px 0', borderTop: '1px solid #f1f5f9', borderBottom: '1px solid #f1f5f9',
                                marginBottom: 14,
                            }}>
                                <div>
                                    <p style={{ margin: '0 0 4px', fontSize: 9, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8' }}>Sales Trend</p>
                                    <Sparkline color={BLUE} />
                                </div>
                                <div style={{ textAlign: 'right' }}>
                                    <p style={{ margin: '0 0 2px', fontSize: 9, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8' }}>Monthly Sales</p>
                                    <p style={{ margin: 0, fontSize: 16, fontWeight: 900, color: '#0f172a' }}>₱{tenant.monthly_sales || '450,000'}</p>
                                </div>
                            </div>

                            {/* Actions */}
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button
                                    onClick={() => navigate(`/commercial/tenants/${tenant.id}`)}
                                    style={{
                                        flex: 1, padding: '8px', borderRadius: 10,
                                        border: `1.5px solid ${BLUE}`, background: 'transparent',
                                        color: BLUE, fontWeight: 800, fontSize: 12, cursor: 'pointer',
                                        transition: 'all 0.15s ease',
                                    }}
                                    onMouseEnter={e => { e.target.style.background = BLUE; e.target.style.color = 'white'; }}
                                    onMouseLeave={e => { e.target.style.background = 'transparent'; e.target.style.color = BLUE; }}
                                >
                                    View Profile
                                </button>
                                <button style={{
                                    flex: 1, padding: '8px', borderRadius: 10,
                                    border: 'none', background: RED,
                                    color: 'white', fontWeight: 800, fontSize: 12, cursor: 'pointer',
                                    opacity: 0.9,
                                    transition: 'opacity 0.15s ease',
                                }}
                                    onMouseEnter={e => { e.target.style.opacity = 1; }}
                                    onMouseLeave={e => { e.target.style.opacity = 0.9; }}
                                >
                                    Financials
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* ── List View ────────────────────────────────────────────── */}
            {viewMode === 'list' && (
                <div style={{ background: 'white', borderRadius: 16, border: '1.5px solid #e2e8f0', overflow: 'hidden', marginBottom: 24, boxShadow: '0 1px 4px rgba(0,0,0,0.04)' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', borderBottom: '2px solid #e2e8f0' }}>
                                {['Tenant', 'Category', 'Location', 'Monthly Sales', 'Status', ''].map(h => (
                                    <th key={h} style={{ padding: '12px 20px', textAlign: 'left', fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8', whiteSpace: 'nowrap' }}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {paginatedTenants.map(tenant => (
                                <tr key={tenant.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                    <td style={{ padding: '12px 20px' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                            <div style={{ width: 36, height: 36, borderRadius: 10, background: '#f1f5f9', border: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', flexShrink: 0 }}>
                                                {tenant.logo_url
                                                    ? <img src={tenant.logo_url} alt={tenant.trade_name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                                    : <StoreIcon style={{ fontSize: 18, color: '#cbd5e1' }} />
                                                }
                                            </div>
                                            <div>
                                                <p style={{ margin: '0 0 1px', fontWeight: 800, color: '#0f172a' }}>{tenant.trade_name}</p>
                                                <p style={{ margin: 0, fontSize: 10, fontFamily: 'monospace', color: '#94a3b8', textTransform: 'uppercase' }}>{tenant.customer_code}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td style={{ padding: '12px 20px' }}><CategoryBadge cat={tenant.category} /></td>
                                    <td style={{ padding: '12px 20px', color: '#475569', fontSize: 12, fontWeight: 500 }}>{tenant.location || 'Level 2'}</td>
                                    <td style={{ padding: '12px 20px', fontWeight: 800, color: BLUE }}>₱{tenant.monthly_sales || '450,000'}</td>
                                    <td style={{ padding: '12px 20px' }}><StatusPill status={tenant.status} /></td>
                                    <td style={{ padding: '12px 20px', textAlign: 'right' }}>
                                        <button
                                            onClick={() => navigate(`/commercial/tenants/${tenant.id}`)}
                                            style={{ padding: '6px 14px', borderRadius: 8, border: `1.5px solid ${BLUE}`, background: 'transparent', color: BLUE, fontWeight: 700, fontSize: 12, cursor: 'pointer' }}
                                        >
                                            Profile
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {paginatedTenants.length === 0 && (
                        <div style={{ padding: '48px 0', textAlign: 'center', color: '#cbd5e1' }}>
                            <GroupIcon style={{ fontSize: 48, display: 'block', margin: '0 auto 8px' }} />
                            <p style={{ margin: 0, fontSize: 12, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em' }}>No tenants match your search</p>
                        </div>
                    )}
                </div>
            )}

            {/* ── Pagination ───────────────────────────────────────────── */}
            {totalPages > 1 && (
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', background: 'white', border: '1.5px solid #e2e8f0', borderRadius: 14, padding: '12px 20px', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
                    <p style={{ margin: 0, fontSize: 12, fontWeight: 600, color: '#64748b' }}>
                        Showing {Math.min(filteredTenants.length, (currentPage - 1) * itemsPerPage + 1)}–{Math.min(filteredTenants.length, currentPage * itemsPerPage)} of {filteredTenants.length} tenants
                    </p>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                        <button
                            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                            disabled={currentPage === 1}
                            style={{ width: 36, height: 36, borderRadius: 9, border: '1.5px solid #e2e8f0', background: 'white', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: currentPage === 1 ? 'not-allowed' : 'pointer', opacity: currentPage === 1 ? 0.3 : 1 }}
                        >
                            <ChevLeft style={{ fontSize: 20, color: '#475569' }} />
                        </button>
                        {[...Array(Math.min(totalPages, 5))].map((_, i) => {
                            const p = i + 1;
                            return (
                                <button key={p} onClick={() => setCurrentPage(p)} style={{ width: 36, height: 36, borderRadius: 9, border: 'none', background: currentPage === p ? BLUE : 'transparent', color: currentPage === p ? 'white' : '#475569', fontWeight: 800, fontSize: 13, cursor: 'pointer' }}>
                                    {p}
                                </button>
                            );
                        })}
                        <button
                            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                            disabled={currentPage === totalPages}
                            style={{ width: 36, height: 36, borderRadius: 9, border: '1.5px solid #e2e8f0', background: 'white', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: currentPage === totalPages ? 'not-allowed' : 'pointer', opacity: currentPage === totalPages ? 0.3 : 1 }}
                        >
                            <ChevRight style={{ fontSize: 20, color: '#475569' }} />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default TenantDirectoryPage;
