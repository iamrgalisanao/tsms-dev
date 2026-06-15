import React, { useState, useMemo, useRef } from 'react';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DownloadForOfflineIcon from '@mui/icons-material/DownloadForOffline';
import UnfoldMoreIcon from '@mui/icons-material/UnfoldMore';
import UnfoldLessIcon from '@mui/icons-material/UnfoldLess';

const highlightCode = (code, language = '') => {
    if (!code) return '';
    const lang = language.toLowerCase();
    
    if (lang === 'json') {
        // Highlighting JSON: keys (cyan), strings (emerald), numbers/booleans (amber/orange), null (rose)
        return code
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/("(\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)/g, (match) => {
                let cls = 'text-[#e5c07b]'; // numbers
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'text-[#e06c75] font-semibold'; // JSON key (soft red/pink)
                    } else {
                        cls = 'text-[#98c379]'; // JSON string (emerald/green)
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'text-[#d19a66]'; // boolean (orange)
                } else if (/null/.test(match)) {
                    cls = 'text-[#c678dd]'; // null (purple)
                }
                return `<span class="${cls}">${match}</span>`;
            });
    }

    if (['curl', 'shell', 'bash', 'fetch', 'axios', 'python', 'javascript'].includes(lang)) {
        return code
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            // Keywords
            .replace(/\b(curl|requests|import|print|const|await|fetch|axios|response|return|def)\b/g, '<span class="text-[#c678dd] font-medium">$1</span>')
            // Option arguments/properties
            .replace(/(--request|--url|--header|--data|method:|url:|headers:|body:|data:)/g, '<span class="text-[#61afef] font-medium">$1</span>')
            // String values
            .replace(/(".*?"|'.*?')/g, '<span class="text-[#98c379]">$1</span>');
    }

    return code;
};

const CodeBlock = ({ value, language = 'json', filename = '', onSendToConsole }) => {
    const [copied, setCopied] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const containerRef = useRef(null);

    const isExpandable = useMemo(() => {
        if (!value) return false;
        const lineCount = value.split('\n').length;
        return lineCount > 10;
    }, [value]);

    const handleCopy = async () => {
        if (!navigator.clipboard) return;
        await navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const handleDownload = () => {
        const blob = new Blob([value], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || `tsms_spec_${Date.now()}.${language === 'json' ? 'json' : 'txt'}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    const highlighted = useMemo(() => highlightCode(value, language), [value, language]);

    return (
        <div 
            ref={containerRef}
            className="relative group rounded-xl overflow-hidden border border-[#c6c6cd]/50 shadow-inner bg-[#0b0f19]"
            role="region"
            aria-label={`${language.toUpperCase()} Code Snippet`}
        >
            {/* Header bar */}
            <div className="flex justify-between items-center px-4 py-2 bg-[#161b22] border-b border-white/5 select-none shrink-0">
                <span className="text-[10px] font-black uppercase text-slate-400 font-mono tracking-wider">
                    {language === 'json' ? 'JSON Payload' : language}
                </span>
                <div className="flex items-center gap-1.5 opacity-60 group-hover:opacity-100 transition-opacity">
                    {onSendToConsole && (
                        <button
                            onClick={onSendToConsole}
                            className="p-1 rounded hover:bg-white/10 text-slate-400 hover:text-white transition-all text-[10px] font-bold uppercase tracking-wider flex items-center gap-1"
                            title="Load into console"
                        >
                            <span>Try</span>
                        </button>
                    )}
                    <button
                        onClick={handleCopy}
                        className="p-1 rounded hover:bg-white/10 text-slate-400 hover:text-[#98c379] transition-all text-[10px] font-bold uppercase tracking-wider flex items-center gap-1"
                        aria-label="Copy to clipboard"
                        title="Copy to clipboard"
                    >
                        <ContentCopyIcon style={{ fontSize: 13 }} />
                        <span>{copied ? 'Copied!' : 'Copy'}</span>
                    </button>
                    <button
                        onClick={handleDownload}
                        className="p-1 rounded hover:bg-white/10 text-slate-400 hover:text-[#61afef] transition-all text-[10px] font-bold uppercase tracking-wider flex items-center gap-1"
                        aria-label="Download snippet as file"
                        title="Download file"
                    >
                        <DownloadForOfflineIcon style={{ fontSize: 13 }} />
                        <span>Save</span>
                    </button>
                </div>
            </div>

            {/* Code container */}
            <div className="relative">
                <pre 
                    className={`m-0 p-4 overflow-x-auto text-[#abb2bf] font-mono text-[13px] leading-relaxed whitespace-pre scrollbar-thin transition-all duration-300 ${
                        isExpandable && !expanded ? 'max-h-[220px] overflow-y-hidden' : ''
                    }`}
                >
                    <code dangerouslySetInnerHTML={{ __html: highlighted || value }} />
                </pre>

                {/* Fade overlay for collapsed blocks */}
                {isExpandable && !expanded && (
                    <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-[#0b0f19] to-transparent pointer-events-none" />
                )}
            </div>

            {/* Expand / Collapse Button */}
            {isExpandable && (
                <div className="flex justify-center bg-[#10141f] border-t border-white/5 py-1 px-3">
                    <button
                        onClick={() => setExpanded(!expanded)}
                        aria-expanded={expanded}
                        className="w-full flex items-center justify-center gap-1 text-[11px] font-bold text-slate-400 hover:text-white py-1 transition-colors focus:outline-none"
                    >
                        {expanded ? (
                            <>
                                <UnfoldLessIcon style={{ fontSize: 14 }} />
                                <span>Collapse snippet</span>
                            </>
                        ) : (
                            <>
                                <UnfoldMoreIcon style={{ fontSize: 14 }} />
                                <span>Expand snippet</span>
                            </>
                        )}
                    </button>
                </div>
            )}
        </div>
    );
};

export default CodeBlock;
