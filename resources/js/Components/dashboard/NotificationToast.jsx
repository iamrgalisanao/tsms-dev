import React, { useEffect, useState } from 'react';

const NotificationToast = ({ message, type = 'info', onClose }) => {
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        const timer = setTimeout(() => {
            setVisible(false);
            if (onClose) setTimeout(onClose, 300); // Wait for fade out
        }, 5000);
        return () => clearTimeout(timer);
    }, [onClose]);

    const bgColors = {
        error: 'bg-red-600',
        warning: 'bg-orange-500',
        success: 'bg-green-600',
        info: 'bg-blue-600'
    };

    if (!visible) return null;

    return (
        <div className={`fixed top-6 right-6 z-[100] ${bgColors[type]} text-white px-6 py-4 rounded-xl shadow-2xl flex items-center space-x-4 animate-in slide-in-from-right fade-in duration-300`}>
            <div className="text-2xl">
                {type === 'error' && '🚨'}
                {type === 'warning' && '⚠️'}
                {type === 'success' && '✅'}
                {type === 'info' && '📢'}
            </div>
            <div>
                <p className="font-bold text-sm leading-tight">{message}</p>
                <p className="text-[10px] opacity-75 font-bold uppercase tracking-widest mt-1">System Notification</p>
            </div>
            <button onClick={() => setVisible(false)} className="hover:opacity-100 opacity-50 transition-opacity">
                ✕
            </button>
        </div>
    );
};

export default NotificationToast;
