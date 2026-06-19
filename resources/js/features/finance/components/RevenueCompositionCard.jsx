import React, { useMemo } from 'react';
import { Box, Typography, Stack } from '@mui/material';
import TimelineIcon from '@mui/icons-material/Timeline';
import InboxIcon from '@mui/icons-material/Inbox';

const GLASS = {
    p: 4,
    borderRadius: '24px',
    border: '1px solid rgba(255,255,255,0.5)',
    boxShadow: '0 8px 32px rgba(0,0,0,0.04)',
    bgcolor: 'rgba(255,255,255,0.75)',
    backdropFilter: 'blur(12px)',
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
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val ?? 0);

function DonutChart({ categories, total }) {
    const r = 52;
    const circ = 2 * Math.PI * r;
    let accumulated = 0;

    return (
        <Box sx={{ position: 'relative', width: 160, height: 160, flexShrink: 0 }}>
            <svg width="100%" height="100%" viewBox="0 0 120 120" style={{ transform: 'rotate(-90deg)' }}>
                {/* Track */}
                <circle cx="60" cy="60" r={r} fill="transparent" stroke="rgba(229,231,245,0.5)" strokeWidth="13" />
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
                            strokeWidth="13"
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
                <Typography variant="caption" sx={{ fontSize: '0.55rem', fontWeight: 800, textTransform: 'uppercase', color: 'text.secondary', letterSpacing: '0.04em' }}>
                    Total
                </Typography>
                <Typography sx={{ fontWeight: 950, fontSize: '0.8rem', color: 'text.primary', textAlign: 'center', px: 0.5, lineHeight: 1.2 }}>
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
        <Box sx={GLASS}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Revenue Composition
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        Tax Exempts, VAT &amp; discounts
                    </Typography>
                </Box>
                <TimelineIcon sx={{ color: 'text.disabled', fontSize: 20 }} />
            </Stack>

            {/* Empty state */}
            {isEmpty ? (
                <Stack flex={1} alignItems="center" justifyContent="center" spacing={1.5} sx={{ py: 4 }}>
                    <Box sx={{ p: 2, bgcolor: 'rgba(29,67,155,0.06)', borderRadius: '50%', display: 'flex' }}>
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
                <Box sx={{ flex: 1, display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, alignItems: 'center', gap: 3 }}>
                    <DonutChart categories={categories} total={total} />
                    <Stack spacing={1.25} sx={{ flex: 1, width: '100%' }}>
                        {categories.map((cat) => {
                            const pct = total > 0 ? ((cat.value / total) * 100).toFixed(1) : '0.0';
                            return (
                                <Box key={cat.name} sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid rgba(229,231,245,0.5)', pb: 0.75 }}>
                                    <Stack direction="row" spacing={1.25} alignItems="center">
                                        <Box sx={{ width: 9, height: 9, borderRadius: '50%', bgcolor: cat.color, flexShrink: 0 }} />
                                        <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary', fontSize: '0.8rem' }}>
                                            {cat.name}
                                        </Typography>
                                    </Stack>
                                    <Typography variant="body2" sx={{ fontWeight: 800, color: 'text.secondary', fontSize: '0.8rem', whiteSpace: 'nowrap' }}>
                                        {formatCurrency(cat.value)}{' '}
                                        <Box component="span" sx={{ color: 'text.disabled', fontWeight: 600, fontSize: '0.7rem' }}>
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
