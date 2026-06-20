import React, { useMemo } from 'react';
import { Box, Typography, Stack } from '@mui/material';
import TimelineIcon from '@mui/icons-material/Timeline';
import InboxIcon from '@mui/icons-material/Inbox';

const CARD_STYLE = {
    p: 2.5,
    borderRadius: '12px',
    border: '1px solid #E2E8F0',
    boxShadow: '0 10px 24px rgba(15,23,42,0.045), 0 1px 2px rgba(15,23,42,0.06)',
    bgcolor: '#FFFFFF',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
};

const COLORS = {
    'Net Sales':    '#10B981',
    'VAT':          '#F59E0B',
    'Tax Exempt':   '#3B82F6',
    'Refunds':      '#EF4444',
    'Discounts':    '#8B5CF6',
};

const formatCurrency = (val) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(val ?? 0);

function DonutChart({ categories, total }) {
    const r = 52;
    const circ = 2 * Math.PI * r;
    let accumulated = 0;

    return (
        <Box sx={{ position: 'relative', width: 180, height: 180, flexShrink: 0 }}>
            <svg width="100%" height="100%" viewBox="0 0 120 120" style={{ transform: 'rotate(-90deg)' }}>
                {/* Track */}
                <circle cx="60" cy="60" r={r} fill="transparent" stroke="rgba(229,231,245,0.3)" strokeWidth="10" />
                {categories.map((cat, idx) => {
                    if (cat.value <= 0) return null;
                    const pct = (cat.value / total) * 100;
                    const offset = circ - (pct * circ) / 100;
                    const rotation = (accumulated / 100) * 360;
                    accumulated += pct;
                    return (
                        <circle
                            key={idx}
                            cx="60" cy="60" r={r}
                            fill="transparent"
                            stroke={cat.color}
                            strokeWidth="10"
                            strokeDasharray={circ}
                            strokeDashoffset={offset}
                            style={{
                                transformOrigin: '60px 60px',
                                transform: `rotate(${rotation}deg)`,
                                transition: 'stroke-dashoffset 0.5s ease',
                            }}
                        />
                    );
                })}
            </svg>
            {/* Centre label */}
            <Box sx={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
                <Typography sx={{ fontSize: '11px', fontWeight: 800, color: '#64748B' }}>
                    Gross Sales
                </Typography>
                <Typography sx={{ fontWeight: 800, fontSize: '18px', color: '#0F172A', textAlign: 'center', px: 0.5, lineHeight: 1.2 }}>
                    {formatCurrency(total)}
                </Typography>
            </Box>
        </Box>
    );
}

export default function RevenueCompositionCard({ data }) {
    const categories = useMemo(() => [
        { name: 'Net Sales',  value: data?.net_sales  ?? 0, color: COLORS['Net Sales']  },
        { name: 'VAT',        value: data?.vat        ?? 0, color: COLORS['VAT']        },
        { name: 'Tax Exempt', value: data?.tax_exempt ?? 0, color: COLORS['Tax Exempt'] },
        { name: 'Refunds',    value: data?.refunds    ?? 0, color: COLORS['Refunds']    },
        { name: 'Discounts',  value: data?.discounts  ?? 0, color: COLORS['Discounts']  },
    ], [data]);

    const total = useMemo(() => categories.reduce((sum, c) => sum + c.value, 0), [categories]);
    const isEmpty = total <= 0;

    return (
        <Box sx={CARD_STYLE}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 800, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Revenue Composition
                    </Typography>
                    <Typography sx={{ fontWeight: 700, color: '#64748B', fontSize: '12px' }}>
                        Tax Exempts, VAT &amp; discounts
                    </Typography>
                </Box>
                <TimelineIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            {/* Empty state */}
            {isEmpty ? (
                <Stack flex={1} alignItems="center" justifyContent="center" spacing={1.5} sx={{ py: 4 }}>
                    <Box sx={{ p: 1.5, bgcolor: '#EEF2FF', borderRadius: '12px', display: 'flex' }}>
                        <InboxIcon sx={{ fontSize: 32, color: 'text.disabled' }} />
                    </Box>
                    <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', textAlign: 'center' }}>
                        No revenue composition available
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.disabled', textAlign: 'center', maxWidth: 220 }}>
                        Revenue breakdown will appear here once transactions are processed for the selected period.
                    </Typography>
                </Stack>
            ) : (
                <Box sx={{ flex: 1, display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, alignItems: 'center', justifyContent: 'center', gap: 4 }}>
                    <DonutChart categories={categories} total={total} />
                    <Stack spacing={1} sx={{ flex: 1, width: '100%' }}>
                        {categories.map((cat) => {
                            const pct = total > 0 ? ((cat.value / total) * 100).toFixed(1) : '0.0';
                            return (
                                <Box key={cat.name} sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #E8ECF4', pb: 0.85 }}>
                                    <Stack direction="row" spacing={1} alignItems="center">
                                        <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: cat.color, flexShrink: 0 }} />
                                        <Typography sx={{ fontWeight: 700, color: '#0F172A', fontSize: '13px' }}>
                                            {cat.name}
                                        </Typography>
                                    </Stack>
                                    <Typography sx={{ fontWeight: 800, color: '#475569', fontSize: '13px', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>
                                        {new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cat.value)}{' '}
                                        <Box component="span" sx={{ color: '#94A3B8', fontWeight: 500, fontSize: '11px', ml: 0.5 }}>
                                            ({pct}%)
                                        </Box>
                                    </Typography>
                                </Box>
                            );
                        })}
                    </Stack>
                </Box>
            )}
        </Box>
    );
}
