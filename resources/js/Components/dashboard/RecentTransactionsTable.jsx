import React from 'react';

const RecentTransactionsTable = ({ transactions, loading, onForward }) => {
    if (loading) {
        return (
            <div className="bg-white rounded-xl shadow-sm p-8 flex justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-xl shadow-sm overflow-hidden">
            <div className="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 className="text-lg font-semibold text-gray-900">Recent Transactions</h3>
                <button className="text-sm text-blue-600 hover:text-blue-800 font-medium">View All</button>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-left table-auto">
                    <thead>
                        <tr className="bg-gray-50/50">
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest w-16">ID</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest min-w-[200px]">Transaction ID</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Tenant</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Net Sales</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Date</th>
                            <th className="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 text-sm">
                        {transactions.length === 0 ? (
                            <tr>
                                <td colSpan="6" className="px-6 py-12 text-center">
                                    <div className="text-gray-400 font-medium">No recent transactions found.</div>
                                </td>
                            </tr>
                        ) : (
                            transactions.map((tx) => (
                                <tr key={tx.id} className="hover:bg-blue-50/30 transition-colors duration-150 group">
                                    <td className="px-6 py-4 font-bold text-gray-400 text-xs">{tx.id}</td>
                                    <td className="px-6 py-4">
                                        <div className="font-mono text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded inline-block whitespace-nowrap">
                                            {tx.transaction_id}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="font-bold text-gray-900 group-hover:text-blue-700 transition-colors">{tx.tenant?.trade_name || 'N/A'}</div>
                                        <div className="text-[10px] text-gray-400 font-bold uppercase tracking-tighter">{tx.display_tenant_code || tx.tenant?.code}</div>
                                    </td>
                                    <td className="px-6 py-4 font-black text-gray-900">
                                        ₱{new Intl.NumberFormat().format(tx.net_sales || 0)}
                                    </td>
                                    <td className="px-6 py-4 text-gray-500 font-medium whitespace-nowrap text-xs">
                                        {tx.transaction_timestamp || 'N/A'}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <button
                                            onClick={() => onForward(tx.id)}
                                            className="px-4 py-1.5 bg-white border border-gray-200 text-gray-700 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all duration-200 shadow-sm"
                                        >
                                            Forward
                                        </button>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default RecentTransactionsTable;
