import React from 'react';

const RolesMatrix = () => {
    const roles = [
        { name: 'Admin', count: 2, icon: 'bolt', color: 'rose', desc: 'Full system access and master control.' },
        { name: 'Finance', count: 3, icon: 'payments', color: 'purple', desc: 'Financial reports, exports, and audits.' },
        { name: 'Operator', count: 8, icon: 'monitor_heart', color: 'blue', desc: 'Daily transaction monitoring and support.' },
        { name: 'Viewer', count: 11, icon: 'visibility', color: 'slate', desc: 'Read-only access to specific dashboards.' },
    ];

    const permissions = [
        {
            category: 'Dashboard', items: [
                { name: 'View Analytics', access: ['admin', 'finance', 'operator', 'viewer'] },
                { name: 'Export Global Statistics', access: ['admin', 'finance'] },
            ]
        },
        {
            category: 'Transactions', items: [
                { name: 'View Real-time Logs', access: ['admin', 'finance', 'operator', 'viewer'] },
                { name: 'Void / Refund Operations', access: ['admin', 'finance'] },
            ]
        },
        {
            category: 'User Management', items: [
                { name: 'Create & Invite New Users', access: ['admin'] },
                { name: 'Assign Roles & Permissions', access: ['admin'] },
            ]
        },
        {
            category: 'Terminal Tokens', items: [
                { name: 'Generate Merchant Tokens', access: ['admin', 'operator'] },
            ]
        },
        {
            category: 'Finance Reports', items: [
                { name: 'Daily Reconciliation', access: ['admin', 'finance'], partial: ['operator'] },
            ]
        },
    ];

    return (
        <div className="animate-in fade-in duration-700">
            {/* Role Cards Section */}
            <section className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                {roles.map((role) => (
                    <div key={role.name}
                        className={`bg-white p-6 rounded-[2rem] border-l-4 shadow-sm flex flex-col gap-4 hover:shadow-xl hover:shadow-${role.color}-100 transition-all duration-300 border-${role.color}-500 group`}
                    >
                        <div className="flex justify-between items-start">
                            <div className={`p-3 rounded-2xl bg-${role.color}-50 text-${role.color}-600 group-hover:scale-110 transition-transform`}>
                                <span className="material-symbols-outlined text-2xl leading-none">{role.icon}</span>
                            </div>
                            <span className={`px-3 py-1 rounded-full text-[10px] font-black bg-${role.color}-500 text-white uppercase tracking-widest`}>
                                {role.count} Users
                            </span>
                        </div>
                        <div>
                            <h3 className="text-slate-900 font-black text-xl leading-tight tracking-tight">{role.name}</h3>
                            <p className="text-slate-400 text-xs font-bold mt-1.5 leading-relaxed">{role.desc}</p>
                        </div>
                    </div>
                ))}
            </section>

            {/* Permissions Matrix Section */}
            <section className="bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden mb-12">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse min-w-[800px]">
                        <thead>
                            <tr className="bg-slate-900">
                                <th className="p-8 text-[11px] font-black text-slate-400 uppercase tracking-[0.2em] w-1/3">Permission Modules</th>
                                {roles.map((role) => (
                                    <th key={role.name} className="p-8 text-center border-l border-slate-800">
                                        <div className="flex flex-col items-center gap-2">
                                            <span className="text-[11px] font-black text-white uppercase tracking-widest">{role.name}</span>
                                            <div className={`w-8 h-1.5 rounded-full bg-${role.color}-500 shadow-lg shadow-${role.color}-500/50`}></div>
                                        </div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="text-sm">
                            {permissions.map((group) => (
                                <React.Fragment key={group.category}>
                                    <tr className="bg-slate-50/80 border-y border-slate-100/50">
                                        <td className="px-8 py-4 text-[11px] font-black text-slate-400 uppercase tracking-widest" colSpan={5}>
                                            <div className="flex items-center gap-2">
                                                <span className="size-2 rounded-full bg-slate-300"></span>
                                                {group.category}
                                            </div>
                                        </td>
                                    </tr>
                                    {group.items.map((item) => (
                                        <tr key={item.name} className="hover:bg-slate-50 transition-colors border-b border-slate-50 group">
                                            <td className="px-8 py-5 text-slate-700 font-black tracking-tight group-hover:text-rose-600 transition-colors">{item.name}</td>
                                            {roles.map((role) => {
                                                const hasAccess = item.access.includes(role.name.toLowerCase());
                                                const isPartial = item.partial?.includes(role.name.toLowerCase());

                                                return (
                                                    <td key={role.name} className="p-4 text-center border-l border-slate-50/50">
                                                        {hasAccess ? (
                                                            <div className="flex justify-center">
                                                                <span className="material-symbols-outlined text-emerald-500 scale-125 transition-transform group-hover:scale-150" style={{ fontVariationSettings: "'FILL' 1" }}>check_circle</span>
                                                            </div>
                                                        ) : isPartial ? (
                                                            <div className="flex justify-center">
                                                                <span className="material-symbols-outlined text-amber-400">horizontal_rule</span>
                                                            </div>
                                                        ) : (
                                                            <div className="flex justify-center opacity-20 transition-opacity group-hover:opacity-40">
                                                                <span className="material-symbols-outlined text-rose-500">cancel</span>
                                                            </div>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </React.Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="px-10 py-5 bg-slate-50 border-t border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-4 text-[10px] text-slate-400 font-black uppercase tracking-widest">
                    <p className="flex items-center gap-2">
                        <span className="material-symbols-outlined text-sm">database</span>
                        Total Permissions Mapped: 48 Access Keys
                    </p>
                    <p className="flex items-center gap-2">
                        <span className="material-symbols-outlined text-sm">sync</span>
                        Last Identity Sync: {new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })}
                    </p>
                </div>
            </section>
        </div>
    );
};

export default RolesMatrix;
