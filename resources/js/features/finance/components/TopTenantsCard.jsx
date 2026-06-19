import React from 'react';
import {
    Box, Typography, Stack,
    Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
} from '@mui/material';
import BusinessIcon from '@mui/icons-material/Business';
import StoreIcon from '@mui/icons-material/Store';

const CARD_STYLE = {
    p: 3,
    borderRadius: '10px',
    border: '1px solid #E8ECF4',
    boxShadow: '0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04)',
    bgcolor: '#FFFFFF',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
};

const formatCurrency = (val) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val ?? 0);

const getCategory = (name) => {
    const lname = name.toLowerCase();
    if (lname.includes('chicken') || lname.includes('inasal') || lname.includes('chatime') || lname.includes('banchan') || lname.includes('food') || lname.includes('juice') || lname.includes('tea')) {
        return 'F&B';
    }
    return 'Retail';
};

export default function TopTenantsCard({ metrics }) {
    const tenants = metrics?.top_tenants ?? [];
    
    // Sum for calculating Share %
    const totalTenantsRevenue = tenants.reduce((acc, t) => acc + t.total_revenue, 0);
    const maxRevenue = Math.max(...tenants.map(t => t.total_revenue), 1);

    return (
        <Box sx={CARD_STYLE}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2.5 }}>
                <Box>
                    <Typography sx={{ fontWeight: 700, fontSize: '16px', color: '#0F172A', mb: 0.5 }}>
                        Top Tenants
                    </Typography>
                    <Typography sx={{ fontWeight: 600, color: '#64748B', textTransform: 'uppercase', letterSpacing: '0.08em', fontSize: '11px' }}>
                        Tenants driving revenue this period
                    </Typography>
                </Box>
                <BusinessIcon sx={{ color: '#94A3B8', fontSize: 20 }} />
            </Stack>

            {/* Empty state */}
            {tenants.length === 0 ? (
                <Stack flex={1} alignItems="center" justifyContent="center" spacing={1.5} sx={{ py: 4 }}>
                    <Box sx={{ p: 2, bgcolor: 'rgba(29,67,155,0.06)', borderRadius: '50%', display: 'flex' }}>
                        <StoreIcon sx={{ fontSize: 32, color: 'text.disabled' }} />
                    </Box>
                    <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.secondary', textAlign: 'center' }}>
                        No tenant revenue yet
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'text.disabled', textAlign: 'center', maxWidth: 240 }}>
                        Tenant revenue will appear here once sales are processed for this period.
                    </Typography>
                </Stack>
            ) : (
                <TableContainer sx={{ boxShadow: 'none', background: 'transparent', flex: 1 }}>
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                {['#', 'Tenant', 'Category', 'Revenue', 'Share'].map((h, i) => (
                                    <TableCell
                                        key={h}
                                        align={i >= 3 ? 'right' : 'left'}
                                        sx={{
                                            fontWeight: 600,
                                            fontSize: '11px',
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.06em',
                                            borderBottom: '2px solid #E8ECF4',
                                            pb: 1.25,
                                            color: '#64748B'
                                        }}
                                    >
                                        {h}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {tenants.map((tenant, idx) => {
                                const category = getCategory(tenant.trade_name);
                                const sharePct = totalTenantsRevenue > 0 ? ((tenant.total_revenue / totalTenantsRevenue) * 100).toFixed(1) : '0.0';
                                const shareWidth = (tenant.total_revenue / maxRevenue) * 100;

                                return (
                                    <TableRow
                                        key={idx}
                                        onClick={() => { window.location.href = `/transactions?search=${encodeURIComponent(tenant.trade_name)}`; }}
                                        sx={{
                                            cursor: 'pointer',
                                            transition: 'background-color 100ms',
                                            '&:hover td': { bgcolor: '#F8FAFC' },
                                            '&:nth-of-type(odd)': { bgcolor: '#FAFBFC' },
                                        }}
                                    >
                                        <TableCell sx={{ fontWeight: 500, color: '#94A3B8', borderBottom: '1px solid #E8ECF4', py: 1.5, width: 40 }}>
                                            {idx + 1}
                                        </TableCell>
                                        <TableCell sx={{ borderBottom: '1px solid #E8ECF4', py: 1.5 }}>
                                            <Typography sx={{ fontWeight: 600, color: '#1A56DB', fontSize: '14px' }}>
                                                {tenant.trade_name}
                                            </Typography>
                                            <Typography sx={{ color: '#94A3B8', fontSize: '11px', mt: 0.5 }}>
                                                View Logs →
                                            </Typography>
                                        </TableCell>
                                        <TableCell sx={{ borderBottom: '1px solid #E8ECF4', py: 1.5 }}>
                                            <Box sx={{
                                                display: 'inline-block',
                                                bgcolor: category === 'F&B' ? '#DCFCE7' : '#EEF2FF',
                                                color: category === 'F&B' ? '#16A34A' : '#1A56DB',
                                                px: 1, py: 0.25,
                                                borderRadius: '4px',
                                                fontSize: '11px',
                                                fontWeight: 600
                                            }}>
                                                {category}
                                            </Box>
                                        </TableCell>
                                        <TableCell align="right" sx={{ borderBottom: '1px solid #E8ECF4', py: 1.5 }}>
                                            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end' }}>
                                                <Typography sx={{ fontWeight: 600, color: '#0F172A', fontSize: '14px', fontVariantNumeric: 'tabular-nums' }}>
                                                    {formatCurrency(tenant.total_revenue)}
                                                </Typography>
                                                {/* Mini rank visualizer bar */}
                                                <Box sx={{ width: 60, height: 3, bgcolor: '#E2E8F0', borderRadius: 1, mt: 0.5, overflow: 'hidden' }}>
                                                    <Box sx={{ width: `${shareWidth}%`, height: '100%', bgcolor: '#1A56DB' }} />
                                                </Box>
                                            </Box>
                                        </TableCell>
                                        <TableCell align="right" sx={{ fontWeight: 500, color: '#64748B', borderBottom: '1px solid #E8ECF4', py: 1.5, fontSize: '13px' }}>
                                            {sharePct}%
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}
        </Box>
    );
}
