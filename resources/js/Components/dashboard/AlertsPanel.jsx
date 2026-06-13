import React from 'react';

const AlertsPanel = ({ alerts = [], loading }) => {
    if (loading) return null;

    if (alerts.length === 0) {
        return (
            <div className="bg-green-50 border border-green-100 rounded-xl p-4 flex items-center space-x-3 mb-8">
                <div className="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white text-sm">✓</div>
                <div>
                    <h4 className="text-sm font-bold text-green-800">System Clear</h4>
                    <p className="text-xs text-green-600">No critical alerts or pending issues detected.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4 mb-8">
            {alerts.slice(0, 3).map((alert, index) => (
                <div key={index} className="bg-red-50 border border-red-100 rounded-xl p-4 flex items-center justify-between animate-in slide-in-from-top duration-300">
                    <div className="flex items-center space-x-3">
                        <div className="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center text-white text-sm font-bold">!</div>
                        <div>
                            <h4 className="text-sm font-bold text-red-800">{alert.title || 'System Alert'}</h4>
                            <p className="text-xs text-red-600">{alert.message || 'Immediate attention required.'}</p>
                        </div>
                    </div>
                    <button className="px-3 py-1 bg-white border border-red-200 text-red-600 rounded-lg text-xs font-bold hover:bg-red-600 hover:text-white transition-colors">
                        Resolve
                    </button>
                </div>
            ))}
        </div>
    );
};

export default AlertsPanel;
