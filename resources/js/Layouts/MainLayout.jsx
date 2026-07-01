import React, { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ThemeProvider } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import Tooltip from '@mui/material/Tooltip';
import theme from '../Themes/MuiTheme';
import DashboardIcon from '@mui/icons-material/Dashboard';
import ReceiptIcon from '@mui/icons-material/Receipt';
import DescriptionIcon from '@mui/icons-material/Description';
import SettingsIcon from '@mui/icons-material/Settings';
import LogoutIcon from '@mui/icons-material/Logout';
import MenuIcon from '@mui/icons-material/Menu';
import KeyIcon from '@mui/icons-material/Key';
import PeopleIcon from '@mui/icons-material/People';
import FlashOnIcon from '@mui/icons-material/FlashOn';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import QueryStatsIcon from '@mui/icons-material/QueryStats';
import BuildIcon from '@mui/icons-material/Build';
import { useAuth } from '../Contexts/AuthContext';

const MainLayout = ({ children }) => {
    const [isSidebarOpen, setIsSidebarOpen] = useState(true);
    const location = useLocation();
    const { user: authUser, logout, loggingOut } = useAuth();
    const fullBleedRoutes = ['/dashboard'];
    const isFullBleedPage = fullBleedRoutes.includes(location.pathname);

    // Merge: prefer reactive AuthContext user, fall back to Blade-injected window.authUser
    const user = authUser || window.authUser || { name: 'Guest', roles: [] };
    const roles = user.roles || [];

    const menuItems = [
        // ── Admin / Manager ──────────────────────────────────────────
        { name: 'Dashboard', path: '/dashboard', icon: DashboardIcon, roles: ['admin', 'manager'] },
        { name: 'Transactions', path: '/transactions', icon: ReceiptIcon, roles: ['admin', 'manager'] },
        { name: 'Terminal Tokens', path: '/terminal-tokens', icon: KeyIcon, roles: ['admin'] },
        { name: 'User Management', path: '/users', icon: PeopleIcon, roles: ['admin'] },
        { name: 'System Logs', path: '/system-logs', icon: DescriptionIcon, roles: ['admin'] },
        { name: 'Intake Health', path: '/observability/intake', icon: FlashOnIcon, roles: ['admin', 'manager'] },
        { name: 'Provider Activity', path: '/monitoring/activity', icon: QueryStatsIcon, roles: ['admin', 'manager'] },
        { name: 'Payload Sandbox', path: '/sandbox/payload', icon: FactCheckIcon, roles: ['admin', 'manager'] },
        { name: 'Corrections', path: '/admin/corrections', icon: BuildIcon, roles: ['admin'] },
        { name: 'Settings', path: '/admin/settings', icon: SettingsIcon, roles: ['admin'] },

        // ── Finance (exclusive set) ───────────────────────────────────
        { name: 'Dashboard', path: '/finance', icon: DashboardIcon, roles: ['finance'] },
        { name: 'Transaction Logs', path: '/transactions', icon: ReceiptIcon, roles: ['finance'] },
        { name: 'CMSR Reports', path: '/reports', icon: DescriptionIcon, roles: ['finance', 'commercial'] },

        // ── Commercial ───────────────────────────────────────────────
        { name: 'Dashboard', path: '/commercial', icon: DashboardIcon, roles: ['commercial'] },
        { name: 'Reports', path: '/commercial/reports', icon: DescriptionIcon, roles: ['commercial'] },
        { name: 'Tenants', path: '/commercial/tenants', icon: PeopleIcon, roles: ['commercial'] },
        { name: 'Tenant Management', path: '/commercial/tenants/manage', icon: DescriptionIcon, roles: ['admin'] },
    ];

    const filteredItems = menuItems.filter(item => {
        if (!item.roles) return true;
        // Normalise to lowercase so 'Commercial' and 'commercial' both match
        const normalisedRoles = roles.map(r => (typeof r === 'string' ? r : r?.name || '').toLowerCase());
        return item.roles.some(role => normalisedRoles.includes(role.toLowerCase()));
    });

    const renderCollapsedTooltip = (label, child) => {
        // Labels are hidden only when the sidebar is collapsed, so tooltips are
        // mounted in that state and removed entirely once the sidebar expands.
        if (isSidebarOpen) return child;

        return (
            <Tooltip
                title={label}
                placement="right"
                arrow
                enterDelay={250}
                leaveDelay={0}
                slotProps={{
                    popper: {
                        sx: {
                            zIndex: 1400,
                            '@keyframes sidebarTooltipWiggle': {
                                '0%, 100%': {
                                    transform: 'translateX(0) rotate(0deg)',
                                },
                                '30%': {
                                    transform: 'translateX(1px) rotate(0.35deg)',
                                },
                                '60%': {
                                    transform: 'translateX(-0.5px) rotate(-0.25deg)',
                                },
                            },
                            '& .MuiTooltip-tooltip': {
                                position: 'relative',
                                bgcolor: '#1D4ED8',
                                color: '#FFFFFF',
                                fontSize: '12px',
                                fontWeight: 700,
                                letterSpacing: '0.01em',
                                border: 'none',
                                borderRadius: '18px 18px 18px 6px',
                                px: 1.5,
                                py: 0.85,
                                backdropFilter: 'blur(14px) saturate(155%)',
                                WebkitBackdropFilter: 'blur(14px) saturate(155%)',
                                boxShadow: '0 14px 32px rgba(15, 23, 42, 0.32), inset 0 1px 0 rgba(191, 219, 254, 0.34)',
                                animation: 'sidebarTooltipWiggle 1.8s ease-in-out infinite',
                                '&::after': {
                                    content: '""',
                                    position: 'absolute',
                                    top: 0,
                                    right: 0,
                                    bottom: 0,
                                    width: '48%',
                                    pointerEvents: 'none',
                                    borderTop: '1px solid rgba(239, 35, 60, 0.86)',
                                    borderRight: '1px solid rgba(239, 35, 60, 0.86)',
                                    borderBottom: '1px solid rgba(239, 35, 60, 0.86)',
                                    borderRadius: '0 18px 18px 0',
                                },
                            },
                            '& .MuiTooltip-arrow': {
                                color: '#1D4ED8',
                                '&::before': {
                                    border: 'none',
                                    boxSizing: 'border-box',
                                    backdropFilter: 'blur(14px) saturate(155%)',
                                    WebkitBackdropFilter: 'blur(14px) saturate(155%)',
                                },
                            },
                        },
                    },
                }}
            >
                {child}
            </Tooltip>
        );
    };

    return (
        <ThemeProvider theme={theme}>
            <CssBaseline />
            <div className="h-screen bg-gray-50 flex overflow-hidden">
                {/* Sidebar */}
                <aside
                    className={`flex-shrink-0 flex flex-col h-full relative z-20 ${isSidebarOpen ? 'w-[220px]' : 'w-20'} transition-all duration-300 font-sans`}
                    style={{
                        background: '#0D1B3E',
                        boxShadow: '16px 0 40px rgba(15, 23, 42, 0.08)'
                    }}
                >
                    {/* Logo area */}
                    <div className="h-20 flex items-center justify-start px-5 bg-white flex-shrink-0 border-b border-gray-150">
                        <img
                            src="/images/pitx_logo.png"
                            alt="PITX Logo"
                            className="h-[28px] object-contain"
                        />
                    </div>
                    {/* Divider below logo */}
                    <div className="h-[1px] bg-white/10 w-full" />

                    {/* Navigation grouping label */}
                    {isSidebarOpen && (
                        <div className="px-5 pt-5 pb-2">
                            <span className="text-[10px] font-bold tracking-widest text-[#A8B8D8]/50 uppercase">
                                Navigation
                            </span>
                        </div>
                    )}

                    <nav className="flex-1 space-y-1 overflow-y-auto no-scrollbar pr-3 pt-2">
                        {filteredItems.map((item) => {
                            const isActive = location.pathname === item.path;
                            const IconComponent = item.icon;
                            const navLink = (
                                <Link
                                    to={item.path}
                                    aria-label={isSidebarOpen ? undefined : item.name}
                                    className={`flex items-center py-2.5 px-4 rounded-r-lg transition-all duration-150 relative group ${isActive
                                        ? 'bg-[#1A2F6B] text-white'
                                        : 'text-[#A8B8D8] hover:bg-[#162558] hover:text-white'
                                        }`}
                                >
                                    {/* Left active border line */}
                                    {isActive && (
                                        <div className="absolute left-0 top-0 bottom-0 w-[3px] bg-[#4A90F5]" />
                                    )}

                                    <IconComponent
                                        sx={{
                                            fontSize: 20,
                                            color: isActive ? '#FFFFFF' : '#A8B8D8',
                                            transition: 'transform 0.2s',
                                            transform: isActive ? 'scale(1.05)' : 'scale(1)',
                                            '.group:hover &': { color: '#FFFFFF' }
                                        }}
                                    />
                                    {isSidebarOpen && (
                                        <span className={`ml-3 text-[14px] ${isActive ? 'font-semibold' : 'font-medium'}`}>
                                            {item.name}
                                        </span>
                                    )}
                                </Link>
                            );

                            return (
                                <React.Fragment key={`${item.path}-${item.name}`}>
                                    {renderCollapsedTooltip(item.name, navLink)}
                                </React.Fragment>
                            );
                        })}
                    </nav>

                    {/* User / Session Footer */}
                    <div className="p-4 border-t border-white/10 mt-auto flex-shrink-0">
                        {isSidebarOpen && (
                            <div className="mb-4 p-3 rounded-lg bg-white/5 border border-white/5">
                                <div className="flex items-center gap-2.5">
                                    <div className="w-8 h-8 rounded-full bg-[#1A56DB] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        {user.name.charAt(0)}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-white text-[13px] font-bold truncate leading-tight">{user.name}</p>
                                        <p className="text-[#A8B8D8] text-[11px] truncate leading-tight mt-0.5">{user.email || ''}</p>
                                    </div>
                                </div>
                                {roles.length > 0 && (
                                    <span className="inline-block mt-2 px-2 py-0.5 rounded-full bg-white/10 text-white/70 text-[9px] font-bold uppercase tracking-wider">
                                        {typeof roles[0] === 'string' ? roles[0] : roles[0]?.name || 'user'}
                                    </span>
                                )}
                            </div>
                        )}
                        {renderCollapsedTooltip(
                            'Logout',
                            <button
                                type="button"
                                disabled={loggingOut}
                                onClick={async (event) => {
                                    event.preventDefault();
                                    await logout();
                                }}
                                aria-label={isSidebarOpen ? undefined : 'Logout'}
                                className="flex items-center w-full p-2.5 rounded-lg text-[#A8B8D8] hover:bg-[#162558] hover:text-white transition-all duration-150 group disabled:cursor-wait disabled:opacity-60"
                            >
                                <LogoutIcon sx={{ fontSize: 18, color: 'inherit' }} />
                                {isSidebarOpen && <span className="ml-3 text-[13px] font-semibold">{loggingOut ? 'Logging out...' : 'Logout'}</span>}
                            </button>
                        )}
                    </div>
                </aside>

                {/* Main Content Area */}
                <div className="flex-1 flex flex-col min-w-0 h-full relative">
                    {/* Navbar */}
                    <header className="h-20 bg-white/95 border-b border-gray-200 flex items-center justify-between px-8 z-10 sticky top-0 backdrop-blur">
                        <button
                            onClick={() => setIsSidebarOpen(!isSidebarOpen)}
                            className="p-2 rounded-lg hover:bg-gray-100 text-gray-400 transition-colors"
                        >
                            <MenuIcon sx={{ fontSize: 28 }} />
                        </button>

                        <div className="flex items-center space-x-4">
                            <div className="text-right hidden sm:block">
                                <p className="text-sm font-semibold text-gray-900">{user.name}</p>
                                <p className="text-xs text-gray-400">{user.email || 'Admin'}</p>
                            </div>
                            <div className="w-10 h-10 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white font-bold border-2 border-white shadow-sm">
                                {user.name.charAt(0)}
                            </div>
                        </div>
                    </header>

                    {/* Scrollable Page Content */}
                    <main 
                        className="flex-1 overflow-y-auto relative"
                        style={{
                            backgroundColor: '#F8FAFC',
                            backgroundImage: `linear-gradient(to right, rgba(29, 67, 155, 0.035) 1px, transparent 1px),
                                              linear-gradient(to bottom, rgba(29, 67, 155, 0.035) 1px, transparent 1px)`,
                            backgroundSize: '24px 24px'
                        }}
                    >
                        <div className={isFullBleedPage ? 'min-h-full' : 'min-h-full px-4 sm:px-6 lg:px-8'}>
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </ThemeProvider>
    );
};

export default MainLayout;
