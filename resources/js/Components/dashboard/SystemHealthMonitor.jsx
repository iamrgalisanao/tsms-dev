import React from 'react';

const SystemHealthMonitor = ({ health, loading }) => {
    if (loading) return (
        <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center justify-center h-40">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        </div>
    );

    const stats = health || { cpu: 0, memory: 0, network: 'Unknown', queues: { backlog: 0 }, forwarding: { status: 'Offline', latency: '0ms' } };

    const getStatusColor = (value) => {
        if (value > 80) return 'bg-red-500';
        if (value > 50) return 'bg-yellow-500';
        return 'bg-green-500';
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h3 className="text-sm font-bold text-gray-900 mb-6 uppercase tracking-widest">System Health</h3>

            <div className="space-y-6">
                {/* CPU Utilization */}
                <div>
                    <div className="flex justify-between text-xs font-bold mb-2">
                        <span className="text-gray-500">CPU UTILIZATION</span>
                        <span className={stats.cpu > 80 ? 'text-red-600' : 'text-gray-900'}>{stats.cpu}%</span>
                    </div>
                    <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            className={`h-full transition-all duration-1000 ${getStatusColor(stats.cpu)}`}
                            style={{ width: `${stats.cpu}%` }}
                        ></div>
                    </div>
                </div>

                {/* Memory Usage */}
                <div>
                    <div className="flex justify-between text-xs font-bold mb-2">
                        <span className="text-gray-500">MEMORY USAGE</span>
                        <span className={stats.memory > 80 ? 'text-red-600' : 'text-gray-900'}>{stats.memory}%</span>
                    </div>
                    <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            className={`h-full transition-all duration-1000 ${getStatusColor(stats.memory)}`}
                            style={{ width: `${stats.memory}%` }}
                        ></div>
                    </div>
                </div>

                {/* Network & Queues */}
                <div className="pt-4 border-t border-gray-50 grid grid-cols-2 gap-4">
                    <div className="space-y-1">
                        <p className="text-[10px] font-bold text-gray-400 uppercase">Network</p>
                        <p className="text-sm font-bold text-green-600 flex items-center">
                            <span className="w-2 h-2 bg-green-500 rounded-full mr-1.5 animate-pulse"></span>
                            {stats.network}
                        </p>
                    </div>
                    <div className="space-y-1">
                        <p className="text-[10px] font-bold text-gray-400 uppercase">Forwarding</p>
                        <p className={`text-sm font-bold ${stats.forwarding.status === 'Active' ? 'text-blue-600' : 'text-gray-400'}`}>
                            {stats.forwarding.status} ({stats.forwarding.latency})
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default SystemHealthMonitor;
