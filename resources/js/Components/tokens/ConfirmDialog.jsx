import React from 'react';

const ConfirmDialog = ({ open, title, message, onConfirm, onClose, type = 'warning' }) => {
    if (!open) return null;

    const typeStyles = {
        warning: 'bg-amber-500 text-white',
        danger: 'bg-rose-500 text-white',
        info: 'bg-blue-500 text-white'
    };

    const iconMap = {
        warning: 'warning',
        danger: 'dangerous',
        info: 'info'
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-300"
                onClick={onClose}
            ></div>

            {/* Modal Content */}
            <div className="relative bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl shadow-slate-900/20 overflow-hidden animate-in zoom-in-95 fade-in duration-300">
                <div className="p-10 space-y-6 text-center">
                    <div className={`mx-auto w-20 h-20 rounded-3xl flex items-center justify-center shadow-xl mb-6 ${typeStyles[type]}`}>
                        <span className="material-symbols-outlined text-4xl">{iconMap[type]}</span>
                    </div>

                    <div className="space-y-2">
                        <h3 className="text-xl font-black text-slate-900 tracking-tight leading-tight uppercase">
                            {title}
                        </h3>
                        <p className="text-sm font-medium text-slate-500 leading-relaxed">
                            {message}
                        </p>
                    </div>

                    <div className="flex flex-col gap-3 pt-4">
                        <button
                            onClick={onConfirm}
                            className={`w-full py-4 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] shadow-lg transition-all hover:scale-[1.02] active:scale-[0.98] ${type === 'danger' ? 'bg-rose-500 text-white shadow-rose-200 hover:bg-rose-600' :
                                    type === 'warning' ? 'bg-amber-500 text-white shadow-amber-200 hover:bg-amber-600' :
                                        'bg-slate-900 text-white shadow-slate-200 hover:bg-slate-800'
                                }`}
                        >
                            Confirm Authorization
                        </button>
                        <button
                            onClick={onClose}
                            className="w-full py-4 bg-slate-50 text-slate-400 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] hover:bg-slate-100 transition-all"
                        >
                            Cancel Request
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ConfirmDialog;
