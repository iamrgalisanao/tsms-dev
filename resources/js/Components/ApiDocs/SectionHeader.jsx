import React from 'react';

const SectionHeader = ({ number, title, description }) => {
    return (
        <div className="border-b border-[#c6c6cd]/40 pb-4 mb-6 mt-10 first:mt-0" role="group" aria-label={`Section ${number}: ${title}`}>
            <span className="text-xs font-black uppercase tracking-widest text-blue-600 block" aria-hidden="true">{number}</span>
            <h2 className="text-2xl font-black text-[#0b1c30] mt-1 tracking-tight">{title}</h2>
            {description && (
                <p className="text-sm text-slate-500 mt-1.5 leading-relaxed">{description}</p>
            )}
        </div>
    );
};

export default SectionHeader;
