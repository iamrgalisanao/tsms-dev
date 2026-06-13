import React, { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ThemeProvider } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
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
import { useAuth } from '../Contexts/AuthContext';

const MainLayout = ({ children }) => {
    const [isSidebarOpen, setIsSidebarOpen] = useState(true);
    const location = useLocation();
    const navigate = useNavigate();
    const { user: authUser, logout } = useAuth();

    // Merge: prefer reactive AuthContext user, fall back to Blade-injected window.authUser
    const user = authUser || window.authUser || { name: 'Guest', roles: [] };
    const roles = user.roles || [];

    const menuItems = [
        // ── Admin / Manager ──────────────────────────────────────────
        { name: 'Dashboard', path: '/dashboard', icon: DashboardIcon, roles: ['admin', 'manager'] },
        { name: 'Transactions', path: '/transactions', icon: ReceiptIcon, roles: ['admin', 'manager'] },
        { name: 'Terminal Tokens', path: '/terminal-tokens', icon: KeyIcon, roles: ['admin', 'commercial'] },
        { name: 'User Management', path: '/users', icon: PeopleIcon, roles: ['admin'] },
        { name: 'System Logs', path: '/system-logs', icon: DescriptionIcon, roles: ['admin'] },
        { name: 'Intake Health', path: '/observability/intake', icon: FlashOnIcon, roles: ['admin', 'manager'] },
        { name: 'Provider Activity', path: '/monitoring/activity', icon: QueryStatsIcon, roles: ['admin', 'manager'] },
        { name: 'Payload Sandbox', path: '/sandbox/payload', icon: FactCheckIcon, roles: ['admin', 'manager'] },
        { name: 'Settings', path: '/settings', icon: SettingsIcon, roles: ['admin'] },

        // ── Finance (exclusive set) ───────────────────────────────────
        { name: 'Dashboard', path: '/finance', icon: DashboardIcon, roles: ['finance'] },
        { name: 'Transaction Logs', path: '/transactions', icon: ReceiptIcon, roles: ['finance'] },
        { name: 'Reports', path: '/reports', icon: DescriptionIcon, roles: ['finance'] },

        // ── Commercial ───────────────────────────────────────────────
        { name: 'Dashboard', path: '/commercial', icon: DashboardIcon, roles: ['commercial'] },
        { name: 'Reports', path: '/commercial/reports', icon: DescriptionIcon, roles: ['commercial'] },
        { name: 'Tenants', path: '/commercial/tenants', icon: PeopleIcon, roles: ['commercial'] },
        { name: 'Tenant Management', path: '/commercial/tenants/manage', icon: DescriptionIcon, roles: ['admin', 'manager'] },
    ];

    const filteredItems = menuItems.filter(item => {
        if (!item.roles) return true;
        // Normalise to lowercase so 'Commercial' and 'commercial' both match
        const normalisedRoles = roles.map(r => (typeof r === 'string' ? r : r?.name || '').toLowerCase());
        return item.roles.some(role => normalisedRoles.includes(role.toLowerCase()));
    });

    return (
        <ThemeProvider theme={theme}>
            <CssBaseline />
            <div className="h-screen bg-gray-50 flex overflow-hidden">
                {/* Sidebar */}
                <aside
                    className={`border-r border-white/10 flex-shrink-0 flex flex-col h-full relative z-20 ${isSidebarOpen ? 'w-64' : 'w-20'} transition-all duration-300 font-sans`}
                    style={{
                        background: 'linear-gradient(180deg, rgba(29,67,155,0.96) 0%, rgba(23,48,111,0.98) 100%)',
                        boxShadow: '16px 0 40px rgba(15, 23, 42, 0.08)'
                    }}
                >
                    <div className="h-24 flex items-center justify-center p-1 bg-white flex-shrink-0">
                        {isSidebarOpen ? (
                            <img
                                src="/images/pitx-logo.png"
                                alt="PITX Logo"
                                className="w-full h-full object-contain"
                            />
                        ) : (
                            <img
                                src="/images/pitx-icon.png"
                                alt="PITX"
                                className="w-full h-full object-contain"
                            />
                        )}
                    </div>

                    <nav className="flex-1 mt-6 px-4 space-y-2 overflow-y-auto no-scrollbar">
                        {filteredItems.map((item) => {
                            const isActive = location.pathname === item.path;
                            const IconComponent = item.icon;
                            return (
                                <Link
                                    key={item.name}
                                    to={item.path}
                                    className={`flex items-center p-3 rounded-lg transition-all duration-200 group ${isActive
                                        ? 'bg-white/15 text-white shadow-sm'
                                        : 'text-white/70 hover:bg-white/10 hover:text-white'
                                        }`}
                                >
                                    <IconComponent
                                        sx={{
                                            fontSize: 24,
                                            transition: 'transform 0.2s',
                                            transform: isActive ? 'scale(1.1)' : 'scale(1)',
                                            '.group:hover &': { transform: 'scale(1.1)' }
                                        }}
                                    />
                                    {isSidebarOpen && <span className={`ml-3 text-[17px] ${isActive ? 'font-bold' : 'font-medium'}`}>{item.name}</span>}
                                    {isActive && isSidebarOpen && <div className="ml-auto w-1.5 h-1.5 rounded-full bg-brand-accent animate-pulse"></div>}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="p-4 border-t border-white/10 mt-auto">
                        <button
                            onClick={async () => {
                                await logout();
                                navigate('/login');
                            }}
                            className="flex items-center w-full p-3 rounded-lg text-white/50 hover:bg-brand-accent/10 hover:text-brand-accent transition-all duration-200"
                        >
                            <LogoutIcon sx={{ fontSize: 24 }} />
                            {isSidebarOpen && <span className="ml-3 text-[18px] font-bold">Logout</span>}
                        </button>
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
                    <main className="flex-1 overflow-y-auto p-8 relative bg-gray-50/50">
                        <div className="max-w-7xl mx-auto">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </ThemeProvider>
    );
};

export default MainLayout;
