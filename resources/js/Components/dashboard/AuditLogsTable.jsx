import React from 'react';

const AuditLogsTable = React.memo(({ logs, loading }) => {
    if (loading) {
        return (
            <div className="bg-white rounded-xl shadow-sm p-8 flex justify-center border border-gray-100 h-64 items-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <div className="p-6 border-b border-gray-100 flex justify-between items-center bg-white">
                <h3 className="text-lg font-bold text-gray-900">System Activity Audit</h3>
                <span className="px-2.5 py-1 bg-gray-100 text-gray-500 rounded-lg text-[10px] font-black uppercase tracking-wider">Live Feed</span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-left table-auto">
                    <thead>
                        <tr className="bg-gray-50/50">
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest w-16">ID</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">User</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Action</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-right">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 text-sm">
                        {logs.length === 0 ? (
                            <tr>
                                <td colSpan="4" className="px-6 py-12 text-center text-gray-400 font-medium">No system activity logged today.</td>
                            </tr>
                        ) : (
                            logs.map((log) => (
                                <tr key={log.id} className="hover:bg-blue-50/30 group">
                                    <td className="px-6 py-4 font-bold text-gray-400 text-xs">{log.id}</td>
                                    <td className="px-6 py-4">
                                        <div className="flex items-center space-x-2">
                                            <div className="w-6 h-6 bg-blue-100 text-blue-600 rounded flex items-center justify-center text-[10px] font-bold">
                                                {log.user?.name ? log.user.name.substring(0, 1) : 'S'}
                                            </div>
                                            <span className="font-bold text-gray-900 group-hover:text-blue-700 transition-colors">
                                                {log.user?.name || 'System Operation'}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className="px-2 py-1 bg-gray-50 text-gray-600 rounded-md text-[10px] font-black uppercase tracking-tighter border border-gray-100">
                                            {log.action}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4 text-gray-500 font-medium whitespace-nowrap text-xs text-right">
                                        {new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
});

export default AuditLogsTable;
