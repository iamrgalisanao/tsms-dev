import React from 'react';

const MethodBadge = ({ method }) => {
    const m = method.toLowerCase();
    const styleMap = {
        get: 'bg-[#dbeafe] text-[#1d4ed8] border-[#93c5fd]',
        post: 'bg-[#dcfce7] text-[#15803d] border-[#86efac]',
        put: 'bg-[#fef3c7] text-[#b45309] border-[#fcd34d]',
        patch: 'bg-[#f3e8ff] text-[#7e22ce] border-[#d8b4fe]',
        delete: 'bg-[#fee2e2] text-[#b91c1c] border-[#fca5a5]'
    };
    const classes = styleMap[m] || styleMap.get;
    return (
        <span className={`px-2 py-0.5 rounded text-[10px] font-mono font-black uppercase border shrink-0 ${classes}`} aria-label={`Method: ${method.toUpperCase()}`}>
            {method.toUpperCase()}
        </span>
    );
};

export default MethodBadge;
