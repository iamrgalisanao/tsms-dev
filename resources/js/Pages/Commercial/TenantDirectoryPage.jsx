import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import '../../../css/TenantDirectory.css';

const TenantDirectoryPage = () => {
    const navigate = useNavigate();
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [viewMode, setViewMode] = useState('grid'); // 'grid' or 'list'
    const [categoryFilter, setCategoryFilter] = useState('All');
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 12;

    const user = window.authUser || { name: 'Admin User', email: 'admin@pitx.ph', roles: [] };
    const userRoles = (user.roles || []).map(r => {
        const name = typeof r === 'string' ? r : r.name;
        return name.replace('-', '_').toLowerCase();
    });

    useEffect(() => {
        fetchTenants();
    }, []);

    const fetchTenants = async () => {
        try {
            setLoading(true);
            const response = await axios.get('/commercial/reports/tenants', {
                headers: { 'Accept': 'application/json' }
            });
            setTenants(response.data);
        } catch (err) {
            setError('Failed to load tenants. Please try again later.');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const filteredTenants = tenants.filter(t => {
        const matchesSearch = t.trade_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
            t.customer_code?.toLowerCase().includes(searchTerm.toLowerCase());
        const matchesCategory = categoryFilter === 'All' || t.category?.toLowerCase() === categoryFilter.toLowerCase();
        return matchesSearch && matchesCategory;
    });

    const totalPages = Math.ceil(filteredTenants.length / itemsPerPage);
    const paginatedTenants = filteredTenants.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

    // Reset pagination when search or filter changes
    useEffect(() => {
        setCurrentPage(1);
    }, [searchTerm, categoryFilter]);

    const metrics = [
        { label: 'Total Tenants', value: tenants.length || '142', change: '+2.4%', color: 'text-primary', icon: 'group', bg: 'bg-primary/5' },
        { label: 'Occupancy Rate', value: '94%', change: '+0.5%', color: 'text-blue-500', icon: 'domain', bg: 'bg-blue-50' },
        { label: 'Monthly Transactions', value: '1.2M', change: '+12.8%', color: 'text-emerald-500', icon: 'payments', bg: 'bg-emerald-50' },
        { label: 'Pending Invoices', value: '5', change: '-2%', color: 'text-orange-500', icon: 'pending_actions', bg: 'bg-orange-50' }
    ];

    if (loading) {
        return (
            <div className="flex items-center justify-center min-h-[400px]">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="p-8">
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl relative" role="alert">
                    <strong className="font-bold">Error! </strong>
                    <span className="block sm:inline">{error}</span>
                </div>
            </div>
        );
    }

    return (
        <div className="flex-1 flex flex-col overflow-hidden -m-8 h-[calc(100vh-64px)]">
            {/* Sticky Header */}
            <header className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-200 px-8 py-4 flex items-center justify-between shrink-0">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 flex items-center gap-2">
                        <span className="material-symbols-outlined text-primary">storefront</span>
                        Tenant Directory
                    </h1>
                    <p className="text-xs text-slate-500 font-medium uppercase tracking-wider">
                        {userRoles.includes('commercial') || userRoles.includes('manager') ? 'Commercial Portal' : 'Administration Portal'}
                    </p>
                </div>
                <div className="flex items-center gap-4 text-slate-400 text-xs font-bold uppercase tracking-widest italic">
                    Live Data View
                </div>
            </header>

            {/* Content Body: Scrollable */}
            <div className="flex-1 overflow-y-auto custom-scrollbar p-8 space-y-6">
                {/* Top Stats Bar */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
                        <div className="size-12 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <span className="material-symbols-outlined text-2xl">group</span>
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-500">Total Tenants</p>
                            <h3 className="text-2xl font-bold">{tenants.length}</h3>
                        </div>
                    </div>
                    <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
                        <div className="size-12 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-500">
                            <span className="material-symbols-outlined text-2xl">square_foot</span>
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-500">Total Area</p>
                            <h3 className="text-2xl font-bold">15,400 <span className="text-base font-medium">sqm</span></h3>
                        </div>
                    </div>
                    <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
                        <div className="size-12 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                            <span className="material-symbols-outlined text-2xl">event_seat</span>
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-500">Current Occupancy</p>
                            <h3 className="text-2xl font-bold">94.2%</h3>
                        </div>
                    </div>
                </div>

                {/* Directory Controls */}
                <div className="flex items-center justify-between gap-4">
                    <div className="flex-1 max-w-xl relative group">
                        <span className="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary transition-colors">search</span>
                        <input
                            className="w-full bg-white border-slate-200 rounded-lg pl-12 pr-4 py-2.5 text-sm focus:ring-primary focus:border-primary transition-all shadow-sm outline-none"
                            placeholder="Search tenants by name, code, or location..."
                            type="text"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>
                    <div className="flex items-center gap-3">
                        <select
                            className="bg-white border-slate-200 rounded-lg px-4 py-2.5 text-sm focus:ring-primary focus:border-primary outline-none shadow-sm font-medium text-slate-600"
                            value={categoryFilter}
                            onChange={(e) => setCategoryFilter(e.target.value)}
                        >
                            <option value="All">All Categories</option>
                            <option value="Food">Food & Beverage</option>
                            <option value="Retail">Retail</option>
                            <option value="Service">Service</option>
                            <option value="Logistics">Logistics</option>
                        </select>
                        <div className="flex items-center bg-white rounded-lg p-1 border border-slate-200 shadow-sm">
                            <button
                                onClick={() => setViewMode('grid')}
                                className={`px-4 py-1.5 rounded-md text-sm font-bold flex items-center gap-2 transition-all ${viewMode === 'grid' ? 'bg-primary text-white shadow-md shadow-primary/20' : 'text-slate-500 hover:text-slate-900'}`}
                            >
                                <span className="material-symbols-outlined text-[18px]">grid_view</span>
                                Grid
                            </button>
                            <button
                                onClick={() => setViewMode('list')}
                                className={`px-4 py-1.5 rounded-md text-sm font-bold flex items-center gap-2 transition-all ${viewMode === 'list' ? 'bg-primary text-white shadow-md shadow-primary/20' : 'text-slate-500 hover:text-slate-900'}`}
                            >
                                <span className="material-symbols-outlined text-[18px]">view_list</span>
                                List
                            </button>
                        </div>
                    </div>
                </div>

                {/* Data Display */}
                {viewMode === 'grid' ? (
                    <section className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        {paginatedTenants.map((tenant) => (
                            <div key={tenant.id} className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-shadow group shrink-0 h-fit">
                                <div className="flex justify-between items-start mb-4">
                                    <div className="flex gap-4">
                                        <div className="size-14 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center overflow-hidden">
                                            {tenant.logo_url ? (
                                                <img className="w-10 h-10 object-contain" alt={tenant.trade_name} src={tenant.logo_url} />
                                            ) : (
                                                <span className="material-symbols-outlined text-slate-300">store</span>
                                            )}
                                        </div>
                                        <div>
                                            <h3 className="font-bold text-slate-900 group-hover:text-primary transition-colors line-clamp-1">{tenant.trade_name}</h3>
                                            <p className="text-xs font-mono text-slate-500 uppercase">{tenant.customer_code}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-end gap-2 shrink-0">
                                        <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider ${tenant.category?.toLowerCase() === 'food' ? 'bg-orange-100 text-orange-700' :
                                            tenant.category?.toLowerCase() === 'retail' ? 'bg-blue-100 text-blue-700' :
                                                'bg-teal-100 text-teal-700'
                                            }`}>
                                            {tenant.category || 'RETAIL'}
                                        </span>
                                        <div className={`flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full ${tenant.status?.toLowerCase() === 'active' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>
                                            <span className={`size-1.5 rounded-full ${tenant.status?.toLowerCase() === 'active' ? 'bg-green-500 animate-pulse' : 'bg-amber-500'}`}></span>
                                            {(tenant.status || 'ACTIVE').toUpperCase()}
                                        </div>
                                    </div>
                                </div>

                                <div className="py-4 border-y border-slate-50 flex items-center justify-between mb-4">
                                    <div className="flex flex-col">
                                        <span className="text-[10px] text-slate-400 font-bold uppercase">Sales Trend</span>
                                        <div className="flex items-end gap-1 h-8 mt-1">
                                            {[20, 35, 25, 50, 75, 40, 60].map((h, i) => (
                                                <div key={i} className={`w-1.5 rounded-t transition-all ${i === 4 ? 'bg-primary' : (i > 4 ? 'bg-primary/40' : 'bg-slate-200')}`} style={{ height: `${h}%` }}></div>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-[10px] text-slate-400 font-bold uppercase">Monthly Sales</span>
                                        <p className="text-lg font-bold text-slate-900">₱{tenant.monthly_sales || '450,000'}</p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <button onClick={() => navigate(`/commercial/tenants/${tenant.id}`)} className="px-4 py-2 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors">
                                        View Profile
                                    </button>
                                    <button className="px-4 py-2 bg-primary text-white rounded-lg text-xs font-bold shadow-sm hover:opacity-90 transition-opacity">
                                        Financials
                                    </button>
                                </div>
                            </div>
                        ))}
                    </section>
                ) : (
                    <div className="bg-white rounded-xl border border-slate-200 shadow-xl overflow-hidden">
                        <table className="w-full text-left border-collapse">
                            <thead className="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Tenant</th>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Category</th>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Location</th>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Monthly Sales</th>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {paginatedTenants.map((tenant) => (
                                    <tr key={tenant.id} className="hover:bg-slate-50/50 transition-colors group">
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="size-10 rounded-full bg-slate-100 flex items-center justify-center overflow-hidden border border-slate-100">
                                                    {tenant.logo_url ? (
                                                        <img className="w-full h-full object-cover" src={tenant.logo_url} alt={tenant.trade_name} />
                                                    ) : (
                                                        <span className="material-symbols-outlined text-slate-300">store</span>
                                                    )}
                                                </div>
                                                <div>
                                                    <p className="font-bold text-slate-900 leading-tight group-hover:text-primary transition-colors">{tenant.trade_name}</p>
                                                    <p className="text-xs text-slate-500">{tenant.customer_code}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-bold ${tenant.category?.toLowerCase() === 'food' ? 'bg-orange-100/50 text-orange-600' : 'bg-blue-100/50 text-blue-600'}`}>
                                                {tenant.category || 'Retail'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-slate-600 font-medium">{tenant.location || 'Level 2, Gate 4'}</td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-col">
                                                <span className="font-bold text-blue-600">₱{tenant.monthly_sales || '450,000'}</span>
                                                <span className="text-[10px] text-emerald-500 font-bold flex items-center">
                                                    <span className="material-symbols-outlined text-[12px]">trending_up</span> 4.2%
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ${tenant.status?.toLowerCase() === 'active' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-amber-50 text-amber-600 border border-amber-100'}`}>
                                                <span className={`size-1.5 rounded-full ${tenant.status?.toLowerCase() === 'active' ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'}`}></span>
                                                {tenant.status || 'Active'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <button onClick={() => navigate(`/commercial/tenants/${tenant.id}`)} className="p-2 text-slate-400 hover:text-primary hover:bg-slate-100 rounded-lg" title="Profile View">
                                                    <span className="material-symbols-outlined text-[20px]">person</span>
                                                </button>
                                                <button className="p-2 text-slate-400 hover:text-blue-500 hover:bg-slate-100 rounded-lg" title="Financials">
                                                    <span className="material-symbols-outlined text-[20px]">bar_chart_4_bars</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Centralized Pagination */}
                <div className="bg-white border border-slate-200 rounded-xl px-6 py-4 flex items-center justify-between shadow-sm">
                    <p className="text-xs font-medium text-slate-500">
                        Showing {Math.min(filteredTenants.length, (currentPage - 1) * itemsPerPage + 1)}-{Math.min(filteredTenants.length, currentPage * itemsPerPage)} of {filteredTenants.length} tenants
                    </p>
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                            disabled={currentPage === 1}
                            className="size-10 rounded-lg border border-slate-200 flex items-center justify-center text-slate-400 hover:text-slate-900 hover:bg-slate-50 disabled:opacity-30 disabled:hover:bg-transparent transition-all"
                        >
                            <span className="material-symbols-outlined text-[20px]">chevron_left</span>
                        </button>

                        <div className="flex items-center gap-1">
                            {[...Array(totalPages)].map((_, i) => {
                                const pageNum = i + 1;
                                // Basic logic to show limited pages if total is large
                                if (totalPages > 5 && Math.abs(pageNum - currentPage) > 2) return null;
                                return (
                                    <button
                                        key={pageNum}
                                        onClick={() => setCurrentPage(pageNum)}
                                        className={`size-10 rounded-lg text-xs font-bold transition-all ${currentPage === pageNum
                                            ? 'bg-primary text-white shadow-lg shadow-primary/20 scale-110'
                                            : 'text-slate-500 hover:bg-slate-50'
                                            }`}
                                    >
                                        {pageNum}
                                    </button>
                                );
                            })}
                        </div>

                        <button
                            onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
                            disabled={currentPage === totalPages || totalPages === 0}
                            className="size-10 rounded-lg border border-slate-200 flex items-center justify-center text-slate-400 hover:text-slate-900 hover:bg-slate-50 disabled:opacity-30 disabled:hover:bg-transparent transition-all"
                        >
                            <span className="material-symbols-outlined text-[20px]">chevron_right</span>
                        </button>
                    </div>
                </div>
            </div>

            {/* Status Toast */}
            <div className="absolute bottom-6 right-8 pointer-events-none">
                <div className="bg-slate-900 text-white px-4 py-2 rounded-lg shadow-2xl flex items-center gap-3 animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <span className="material-symbols-outlined text-emerald-500 text-sm">check_circle</span>
                    <p className="text-[10px] font-bold">System Online & Synced</p>
                </div>
            </div>
        </div>
    );
};

export default TenantDirectoryPage;
