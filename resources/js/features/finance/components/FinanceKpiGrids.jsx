import React, { useMemo } from 'react';
import { Grid, Box } from '@mui/material';
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
        <Grid container spacing={3} sx={{ mb: 3 }}>
            <Grid item xs={12} sm={6} lg={4}>
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
            </Grid>
            <Grid item xs={12} sm={6} lg={4}>
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
            </Grid>
            <Grid item xs={12} sm={6} lg={4}>
                <FinanceKpiCard
                    title="Reconciled"
                    value={`${reconDone.toLocaleString()} of ${reconTotal.toLocaleString()}`}
                    subtitle={`${reconPct}% completed · ${(metrics?.reconciliation?.pending ?? 0).toLocaleString()} pending`}
                    trend={parseFloat(reconPct)}
                    trendDirection={parseFloat(reconPct) < 99.5 ? 'down' : 'up'}
                    trendPositive
                    icon={<CheckCircleIcon sx={{ fontSize: 22 }} />}
                    tooltip="Transactions successfully processed through the sharded queue. Excludes pending ingestion and failed exceptions."
                />
            </Grid>
        </Grid>
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
        <Grid container spacing={3} sx={{ mb: 4 }}>
            <Grid item xs={12} sm={6} lg={4}>
                <FinanceKpiCard
                    title="Refunds"
                    value={formatCurrency(metrics?.revenue_composition?.refunds)}
                    subtitle={refundsPct}
                    icon={<ReceiptLongIcon sx={{ fontSize: 22 }} />}
                    tooltip="Total sales returned or refunded — monitored for transaction leakage and merchant compliance."
                />
            </Grid>
            <Grid item xs={12} sm={6} lg={4}>
                <FinanceKpiCard
                    title="Discounts"
                    value={formatCurrency(metrics?.revenue_composition?.discounts)}
                    subtitle={discountsPct}
                    icon={<TrendingDownIcon sx={{ fontSize: 22 }} />}
                    tooltip="Senior citizen, PWD, promotional, and regular merchant discounts deducted from gross transactions."
                />
            </Grid>
            <Grid item xs={12} sm={6} lg={4}>
                <FinanceKpiCard
                    title="Voided Transactions"
                    value={(metrics?.voided_transactions?.current ?? 0).toLocaleString()}
                    subtitle={voidPct}
                    trend={metrics?.voided_transactions?.trend}
                    trendDirection={metrics?.voided_transactions?.trend < 0 ? 'down' : 'up'}
                    icon={<ErrorIcon sx={{ fontSize: 22 }} />}
                    tooltip="Transactions voided at point-of-sale. High void rates may indicate operational errors or transaction fraud."
                />
            </Grid>
        </Grid>
    );
}
