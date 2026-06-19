import React from 'react';
import {
    Box, Typography, Stack,
    Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
} from '@mui/material';
import BusinessIcon from '@mui/icons-material/Business';
import StoreIcon from '@mui/icons-material/Store';

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

const formatCurrency = (val) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val ?? 0);

export default function TopTenantsCard({ metrics }) {
    const tenants = metrics?.top_tenants ?? [];

    return (
        <Box sx={GLASS}>
            {/* Header */}
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 3 }}>
                <Box>
                    <Typography variant="body1" sx={{ fontWeight: 900, color: 'text.primary', letterSpacing: '-0.01em' }}>
                        Top Tenants
                    </Typography>
                    <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.55 }}>
                        Tenants driving revenue this period
                    </Typography>
                </Box>
                <BusinessIcon sx={{ color: 'text.disabled', fontSize: 20 }} />
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
                                {['#', 'Tenant', 'Revenue'].map((h, i) => (
                                    <TableCell
                                        key={h}
                                        align={i === 2 ? 'right' : 'left'}
                                        sx={{ fontWeight: 800, fontSize: '0.7rem', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '2px solid rgba(229,231,245,0.8)', pb: 1.25, color: 'text.secondary' }}
                                    >
                                        {h}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {tenants.map((tenant, idx) => (
                                <TableRow
                                    key={idx}
                                    onClick={() => { window.location.href = `/transactions?search=${encodeURIComponent(tenant.trade_name)}`; }}
                                    sx={{
                                        cursor: 'pointer',
                                        '&:hover td': { bgcolor: 'rgba(29,67,155,0.03)' },
                                        transition: 'background-color 0.15s',
                                    }}
                                >
                                    <TableCell sx={{ fontWeight: 700, color: 'text.disabled', borderBottom: '1px solid rgba(229,231,245,0.4)', py: 1.5, width: 40 }}>
                                        {idx + 1}
                                    </TableCell>
                                    <TableCell sx={{ borderBottom: '1px solid rgba(229,231,245,0.4)', py: 1.5 }}>
                                        <Typography sx={{ fontWeight: 750, color: 'primary.main', fontSize: '0.85rem' }}>
                                            {tenant.trade_name}
                                        </Typography>
                                        <Typography variant="caption" sx={{ color: 'text.disabled', fontSize: '0.65rem' }}>
                                            View Logs →
                                        </Typography>
                                    </TableCell>
                                    <TableCell align="right" sx={{ fontWeight: 800, color: 'text.primary', borderBottom: '1px solid rgba(229,231,245,0.4)', py: 1.5, fontSize: '0.85rem', whiteSpace: 'nowrap' }}>
                                        {formatCurrency(tenant.total_revenue)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}
        </Box>
    );
}
