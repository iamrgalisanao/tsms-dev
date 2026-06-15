import React, { useState, useMemo } from 'react';
import SearchIcon from '@mui/icons-material/Search';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';

const ROOT_SCHEMA = [
    ['submission_uuid', 'Yes', 'UUID string', 'Unique submission id. Resending the same UUID with the same checksum is idempotent; changing the payload is rejected.'],
    ['tenant_id', 'Yes', 'Integer', 'Must be the tenant that owns the authenticated terminal.'],
    ['terminal_id', 'Yes', 'Integer', 'Must match the terminal identity of the bearer token. Token sharing across terminals is rejected.'],
    ['submission_timestamp', 'Yes', 'YYYY-MM-DDTHH:mm:ssZ', 'UTC timestamp with no fractional seconds.'],
    ['transaction_count', 'Yes', 'Integer, exactly 1', 'Official ingestion accepts one transaction per submission.'],
    ['payload_checksum', 'Yes', '64-character SHA-256', 'Computed from the canonical submission after transaction payload checksums are already present.'],
    ['transaction', 'Yes', 'Object', 'Required official transaction object.'],
    ['transactions', 'No', 'Unsupported', 'Batch ingestion is not supported on the official provider endpoint.']
];

const TRANSACTION_SCHEMA = [
    ['transaction_id', 'Yes', 'UUID string', 'Unique transaction id inside the submitted payload.'],
    ['hardware_id', 'Yes', 'String', 'Must equal the authenticated terminal serial_number. Mismatches are rejected with HARDWARE_ID_MISMATCH.'],
    ['transaction_timestamp', 'Yes', 'YYYY-MM-DDTHH:mm:ssZ', 'UTC timestamp with no fractional seconds.'],
    ['receipt_no', 'Yes', 'Letters, numbers, dash, dot; max 128', 'Receipt identity used for duplicate detection on the same terminal and date.'],
    ['gross_sales', 'Yes', 'Numeric, minimum 0', 'Submitted value is stored after validation.'],
    ['net_sales', 'Yes', 'Numeric, minimum 0', 'Submitted value is stored after validation; TSMS does not recompute this from gross sales.'],
    ['promo_status', 'Yes', 'String', 'Submitted POS promo status.'],
    ['customer_code', 'Yes', 'String', 'Submitted POS customer code.'],
    ['payload_checksum', 'Yes', '64-character SHA-256', 'Computed from the canonical transaction object before adding this field.'],
    ['adjustments', 'Yes', 'Array, minimum 7 rows', 'Each row requires adjustment_type and amount. Include zero-amount rows for unused adjustment types.'],
    ['taxes', 'Yes', 'Array, minimum 4 rows', 'Each row requires tax_type and amount. Include VAT, VATABLE_SALES, SC_VAT_EXEMPT_SALES, and OTHER_TAX rows.']
];

const NESTED_SCHEMA = [
    ['adjustments[].adjustment_type', 'Yes', 'String', 'Examples: promo_discount, senior_discount, pwd_discount, vip_card_discount, employee_discount.'],
    ['adjustments[].amount', 'Yes', 'Numeric', 'Use two-decimal numeric strings for stable checksum canonicalization.'],
    ['taxes[].tax_type', 'Yes', 'String', 'Examples: VAT, VATABLE_SALES, SC_VAT_EXEMPT_SALES, OTHER_TAX.'],
    ['taxes[].amount', 'Yes', 'Numeric', 'Use two-decimal numeric strings for stable checksum canonicalization.']
];

const SchemaExplorer = () => {
    const [searchQuery, setSearchQuery] = useState('');

    const filterFields = (fields) => {
        const query = searchQuery.trim().toLowerCase();
        if (!query) return fields;
        return fields.filter(
            ([name, req, val, behavior]) =>
                name.toLowerCase().includes(query) ||
                req.toLowerCase().includes(query) ||
                val.toLowerCase().includes(query) ||
                behavior.toLowerCase().includes(query)
        );
    };

    const rootFiltered = useMemo(() => filterFields(ROOT_SCHEMA), [searchQuery]);
    const transFiltered = useMemo(() => filterFields(TRANSACTION_SCHEMA), [searchQuery]);
    const nestedFiltered = useMemo(() => filterFields(NESTED_SCHEMA), [searchQuery]);

    const hasAnyResults = rootFiltered.length > 0 || transFiltered.length > 0 || nestedFiltered.length > 0;

    const renderSchemaGroupList = (title, items) => {
        if (items.length === 0) return null;
        return (
            <div className="space-y-2.5">
                {items.map(([field, required, validation, behavior]) => {
                    const isReq = required.toLowerCase() === 'yes';
                    return (
                        <details 
                            key={field} 
                            className="group border border-[#c6c6cd]/50 rounded-xl bg-white hover:border-[#c6c6cd] transition-all overflow-hidden"
                        >
                            <summary className="p-3.5 cursor-pointer font-mono text-xs font-bold text-slate-800 flex justify-between items-center select-none bg-slate-50/30">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-blue-700 font-black">{field}</span>
                                    <span className={`text-[9px] px-1.5 py-0.5 rounded border uppercase font-sans font-bold ${
                                        isReq 
                                            ? 'bg-amber-50 text-amber-700 border-amber-200' 
                                            : 'bg-slate-50 text-slate-500 border-slate-200'
                                    }`}>
                                        {required}
                                    </span>
                                    <span className="text-[9px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100 uppercase font-sans">
                                        {validation}
                                    </span>
                                </div>
                                <span className="text-slate-400 group-open:rotate-180 transition-transform">
                                    <ExpandMoreIcon style={{ fontSize: 18 }} />
                                </span>
                            </summary>
                            <div className="p-4 bg-white border-t border-[#c6c6cd]/30 space-y-2.5 text-xs text-slate-600">
                                <div>
                                    <span className="block text-[9px] uppercase font-black tracking-wider text-slate-400 mb-0.5">Validation Rules</span>
                                    <p className="leading-relaxed">{validation}</p>
                                </div>
                                <div>
                                    <span className="block text-[9px] uppercase font-black tracking-wider text-slate-400 mb-0.5">TSMS System Behavior</span>
                                    <p className="leading-relaxed font-medium text-slate-800">{behavior}</p>
                                </div>
                            </div>
                        </details>
                    );
                })}
            </div>
        );
    };

    return (
        <div className="space-y-4">
            {/* Search Input */}
            <div className="relative">
                <input
                    type="text"
                    placeholder="Search payload fields (e.g. uuid, checksum, amount)..."
                    className="w-full bg-white border border-[#c6c6cd] rounded-xl pl-10 pr-4 py-3 text-sm text-[#0b1c30] placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 shadow-sm"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                />
                <div className="absolute left-3.5 top-3.5 text-slate-400">
                    <SearchIcon style={{ fontSize: 18 }} />
                </div>
            </div>

            {/* Mutually Exclusive Details Accordions */}
            {hasAnyResults ? (
                <div className="space-y-4">
                    {rootFiltered.length > 0 && (
                        <details className="group border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm" name="schema-groups" open>
                            <summary className="p-4 bg-slate-50 cursor-pointer font-bold text-sm text-[#0b1c30] select-none flex items-center justify-between group-open:border-b border-[#c6c6cd] hover:bg-slate-100/50">
                                <span className="flex items-center gap-2">
                                    <span>Root submission envelope</span>
                                    <span className="text-xs bg-[#eff4ff] text-blue-700 px-2 py-0.5 rounded-full border border-blue-100 font-mono font-bold">
                                        {rootFiltered.length} fields
                                    </span>
                                </span>
                                <span className="transition-transform group-open:rotate-180">
                                    <ExpandMoreIcon />
                                </span>
                            </summary>
                            <div className="p-4 space-y-2.5 bg-slate-50/20">
                                {renderSchemaGroupList('Root Submission Envelope', rootFiltered)}
                            </div>
                        </details>
                    )}

                    {transFiltered.length > 0 && (
                        <details className="group border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm" name="schema-groups">
                            <summary className="p-4 bg-slate-50 cursor-pointer font-bold text-sm text-[#0b1c30] select-none flex items-center justify-between group-open:border-b border-[#c6c6cd] hover:bg-slate-100/50">
                                <span className="flex items-center gap-2">
                                    <span>Transaction object details</span>
                                    <span className="text-xs bg-[#eff4ff] text-blue-700 px-2 py-0.5 rounded-full border border-blue-100 font-mono font-bold">
                                        {transFiltered.length} fields
                                    </span>
                                </span>
                                <span className="transition-transform group-open:rotate-180">
                                    <ExpandMoreIcon />
                                </span>
                            </summary>
                            <div className="p-4 space-y-2.5 bg-slate-50/20">
                                {renderSchemaGroupList('Transaction Object Details', transFiltered)}
                            </div>
                        </details>
                    )}

                    {nestedFiltered.length > 0 && (
                        <details className="group border border-[#c6c6cd] rounded-xl overflow-hidden bg-white shadow-sm" name="schema-groups">
                            <summary className="p-4 bg-slate-50 cursor-pointer font-bold text-sm text-[#0b1c30] select-none flex items-center justify-between group-open:border-b border-[#c6c6cd] hover:bg-slate-100/50">
                                <span className="flex items-center gap-2">
                                    <span>Adjustment and tax nested rows</span>
                                    <span className="text-xs bg-[#eff4ff] text-blue-700 px-2 py-0.5 rounded-full border border-blue-100 font-mono font-bold">
                                        {nestedFiltered.length} fields
                                    </span>
                                </span>
                                <span className="transition-transform group-open:rotate-180">
                                    <ExpandMoreIcon />
                                </span>
                            </summary>
                            <div className="p-4 space-y-2.5 bg-slate-50/20">
                                {renderSchemaGroupList('Adjustment and Tax Nested Rows', nestedFiltered)}
                            </div>
                        </details>
                    )}
                </div>
            ) : (
                <div className="text-sm text-slate-400 p-8 text-center border border-[#c6c6cd]/50 border-dashed rounded-xl bg-white select-none">
                    No schema fields match your filter criteria. Try searching for "uuid", "sales", or "tax".
                </div>
            )}
        </div>
    );
};

export default SchemaExplorer;
