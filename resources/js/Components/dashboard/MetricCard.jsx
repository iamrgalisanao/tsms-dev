import React from 'react';

const MetricCard = ({ title, value, icon, color = 'blue', trend, sparkline }) => {
    const colorClasses = {
        blue: 'bg-blue-500 text-blue-500',
        red: 'bg-red-500 text-red-500',
        green: 'bg-green-500 text-green-500',
        yellow: 'bg-yellow-500 text-yellow-500',
        indigo: 'bg-indigo-500 text-indigo-500',
        purple: 'bg-purple-500 text-purple-500',
        pink: 'bg-pink-500 text-pink-500',
    };

    const bgClass = colorClasses[color].split(' ')[0];
    const textClass = colorClasses[color].split(' ')[1];

    return (
        <div className="bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden group h-full flex flex-col min-h-[140px] border border-gray-100">
            <div className="p-5 flex-1">
                <div className="flex items-start justify-between">
                    <div className={`${bgClass} p-3 rounded-lg text-white group-hover:scale-110 transition-transform duration-300 shadow-sm`}>
                        <span className="text-xl">{icon}</span>
                    </div>
                    {trend !== undefined && (
                        <div className={`flex items-center space-x-1 text-xs font-bold ${trend >= 0 ? 'text-green-500' : 'text-red-500'}`}>
                            <span>{trend >= 0 ? '↑' : '↓'}</span>
                            <span>{Math.abs(trend)}%</span>
                        </div>
                    )}
                </div>

                <div className="mt-4">
                    <p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">{title}</p>
                    <div className="flex items-baseline justify-between">
                        <h3 className="text-2xl font-black text-gray-900 leading-tight">{value}</h3>
                    </div>
                </div>

                {sparkline && sparkline.length > 0 && (
                    <div className="mt-4 h-8 w-full opacity-50 group-hover:opacity-100 transition-opacity">
                        <svg viewBox="0 0 100 20" className="w-full h-full overflow-visible">
                            <path
                                d={`M ${sparkline.map((val, i) => `${(i / (sparkline.length - 1)) * 100} ${20 - (val / Math.max(...sparkline, 1)) * 18}`).join(' L ')}`}
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className={textClass}
                            />
                        </svg>
                    </div>
                )}
            </div>
            <div className={`h-1.5 w-full ${bgClass} opacity-10 group-hover:opacity-100 transition-opacity duration-300 mt-auto`}></div>
        </div>
    );
};

export default MetricCard;
