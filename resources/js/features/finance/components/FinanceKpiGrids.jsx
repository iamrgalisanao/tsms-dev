import React, { useMemo } from 'react';
import { Grid, Box, Typography, Stack } from '@mui/material';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import AnalyticsIcon from '@mui/icons-material/Analytics';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import TrendingDownIcon from '@mui/icons-material/TrendingDown';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import ErrorIcon from '@mui/icons-material/Error';
import FinanceKpiCard from './FinanceKpiCard';

const formatCurrency = (val) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val ?? 0);

// ──────────────────────────────────────────────────────────────────────────────
// Row 1: Gross Sales | Net Sales | Reconciled
// ──────────────────────────────────────────────────────────────────────────────
export function FinanceKpiGrid({ metrics, dateRange }) {
    const reconTotal = metrics?.reconciliation?.total ?? 0;
    const reconDone = metrics?.reconciliation?.reconciled ?? 0;
    const reconPct = reconTotal > 0 ? ((reconDone / reconTotal) * 100).toFixed(1) : '0.0';

    return (
        <Box sx={{
            display: 'grid',
            gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: '1fr 1fr 1fr' },
            gap: 2.5,
            width: '100%',
            mb: 2.5
        }}>
            <FinanceKpiCard
                title="Gross Sales"
                value={formatCurrency(metrics?.total_sales?.current)}
                subtitle={`vs previous ${dateRange} days`}
                trend={metrics?.total_sales?.trend}
                trendDirection={metrics?.total_sales?.trend < 0 ? 'down' : 'up'}
                trendPositive
                icon={<TrendingUpIcon sx={{ fontSize: 22 }} />}
                tooltip="Total gross sales recorded by POS terminals before tax exemptions, discounts, or adjustments."
            />
            <FinanceKpiCard
                title="Net Sales"
                value={formatCurrency(metrics?.total_net_sales?.current)}
                subtitle={`vs previous ${dateRange} days`}
                trend={metrics?.total_net_sales?.trend}
                trendDirection={metrics?.total_net_sales?.trend < 0 ? 'down' : 'up'}
                trendPositive
                icon={<AnalyticsIcon sx={{ fontSize: 22 }} />}
                tooltip="Net revenue after deducting VAT, exemptions, senior/PWD discounts, and service charges."
            />
            <FinanceKpiCard
                title="Reconciled"
                value={
                    <Box sx={{ width: '100%' }}>
                        <Typography sx={{ fontWeight: 800, fontSize: '24px', color: '#0F172A', lineHeight: 1.2 }}>
                            {reconDone.toLocaleString()} of {reconTotal.toLocaleString()}
                        </Typography>
                        <Box sx={{ width: '100%', height: 4, bgcolor: '#E2E8F0', borderRadius: 2, my: 1, overflow: 'hidden' }}>
                            <Box sx={{ width: `${reconPct}%`, height: '100%', bgcolor: parseFloat(reconPct) < 99.5 ? '#D97706' : '#16A34A' }} />
                        </Box>
                    </Box>
                }
                subtitle={`${reconPct}% completed · ${(metrics?.reconciliation?.pending ?? 0).toLocaleString()} pending`}
                icon={<CheckCircleIcon sx={{ fontSize: 22 }} />}
                tooltip="Transactions successfully processed through the sharded queue. Excludes pending ingestion and failed exceptions."
            />
        </Box>
    );
}

// ──────────────────────────────────────────────────────────────────────────────
// Row 2: Refunds | Discounts | Voided Transactions
// ──────────────────────────────────────────────────────────────────────────────
export function FinanceLeakageGrid({ metrics, dateRange }) {
    const gross = metrics?.total_sales?.current ?? 0;

    const refundsPct = gross > 0
        ? `${(((metrics?.revenue_composition?.refunds ?? 0) / gross) * 100).toFixed(2)}% of gross`
        : 'of gross';
    const discountsPct = gross > 0
        ? `${(((metrics?.revenue_composition?.discounts ?? 0) / gross) * 100).toFixed(2)}% of gross`
        : 'of gross';

    const voidTotal = metrics?.reconciliation?.total ?? 0;
    const voidCount = metrics?.voided_transactions?.current ?? 0;
    const voidPct = voidTotal > 0
        ? `${((voidCount / voidTotal) * 100).toFixed(2)}% of total volume`
        : 'of total volume';

    return (
        <Box sx={{
            display: 'grid',
            gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: '1fr 1fr 1fr' },
            gap: 2.5,
            width: '100%',
            mb: 3
        }}>
            <FinanceKpiCard
                title="Refunds"
                value={formatCurrency(metrics?.revenue_composition?.refunds)}
                subtitle={refundsPct}
                icon={<ReceiptLongIcon sx={{ fontSize: 22 }} />}
                tooltip="Total sales returned or refunded — monitored for transaction leakage and merchant compliance."
            />
            <FinanceKpiCard
                title="Discounts"
                value={formatCurrency(metrics?.revenue_composition?.discounts)}
                subtitle={discountsPct}
                icon={<TrendingDownIcon sx={{ fontSize: 22 }} />}
                tooltip="Senior citizen, PWD, promotional, and regular merchant discounts deducted from gross transactions."
            />
            <FinanceKpiCard
                title="Voided Transactions"
                value={(metrics?.voided_transactions?.current ?? 0).toLocaleString()}
                subtitle={
                    <Stack direction="row" spacing={0.5} alignItems="center" sx={{
                        bgcolor: '#FEF3C7',
                        color: '#D97706',
                        px: 1, py: 0.25, borderRadius: '4px',
                        fontWeight: 600, fontSize: '11px',
                        display: 'inline-flex',
                        mt: 0.5
                    }}>
                        <span>⚠ {voidPct}</span>
                    </Stack>
                }
                trend={metrics?.voided_transactions?.trend}
                trendDirection={metrics?.voided_transactions?.trend < 0 ? 'down' : 'up'}
                icon={<ErrorIcon sx={{ fontSize: 22 }} />}
                tooltip="Transactions voided at point-of-sale. High void rates may indicate operational errors or transaction fraud."
            />
        </Box>
    );
}
