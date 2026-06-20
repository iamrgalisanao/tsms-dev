import React from 'react';
import { Box, Grid } from '@mui/material';
import { useFinanceDashboard } from '../../features/finance/hooks/useFinanceDashboard';
import FinanceLoadingSkeleton from '../../features/finance/components/FinanceLoadingSkeleton';
import FinanceDashboardHeader from '../../features/finance/components/FinanceDashboardHeader';
import { FinanceKpiGrid, FinanceLeakageGrid } from '../../features/finance/components/FinanceKpiGrids';
import FinanceAlerts from '../../features/finance/components/FinanceAlerts';
import RevenueTrendCard from '../../features/finance/components/RevenueTrendCard';
import RevenueCompositionCard from '../../features/finance/components/RevenueCompositionCard';
import TopTenantsCard from '../../features/finance/components/TopTenantsCard';
import QuickActionsHub from '../../features/finance/components/QuickActionsHub';

export default function FinanceDashboardPage() {
    const { metrics, charts, loading, refreshing, dateRange, setDateRange, refetch } =
        useFinanceDashboard();

    if (loading) return <FinanceLoadingSkeleton />;

    return (
        <Box
            sx={{
                width: '100%',
                maxWidth: 1320,
                mx: 'auto',
                px: { xs: 2, sm: 3, xl: 0 },
                pb: 8,
                '&::before': {
                    content: '""',
                    position: 'fixed',
                    inset: 0,
                    pointerEvents: 'none',
                    backgroundImage: 'linear-gradient(rgba(148,163,184,0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,0.06) 1px, transparent 1px)',
                    backgroundSize: '24px 24px',
                    zIndex: -1,
                },
            }}
        >

            {/* ── Row 1: Header / Sync / Period ──────────────────────── */}
            <FinanceDashboardHeader
                dateRange={dateRange}
                onDateChange={setDateRange}
                onRefresh={refetch}
                refreshing={refreshing}
            />

            {/* ── Row 2: Gross Sales | Net Sales | Reconciled ─────────── */}
            <FinanceKpiGrid metrics={metrics} dateRange={dateRange} />

            {/* ── Row 3: Refunds | Discounts | Voided Transactions ─────── */}
            <FinanceLeakageGrid metrics={metrics} dateRange={dateRange} />

            {/* ── Row 4: Finance Alerts (full width) ──────────────────── */}
            <FinanceAlerts metrics={metrics} />

            {/* ── Row 6: Revenue Trend 50% | Revenue Composition 50% ──── */}
            <Grid container spacing={3} sx={{ mb: 3 }}>
                <Grid item xs={12} lg={6}>
                    <RevenueTrendCard charts={charts} />
                </Grid>
                <Grid item xs={12} lg={6}>
                    <RevenueCompositionCard data={metrics?.revenue_composition} />
                </Grid>
            </Grid>

            {/* ── Row 7: Top Tenants ───────────────────────────────────── */}
            <Grid container spacing={3} sx={{ mb: 3 }}>
                <Grid item xs={12} lg={8}>
                    <TopTenantsCard metrics={metrics} />
                </Grid>
                <Grid item xs={12} lg={4}>
                    <QuickActionsHub compact />
                </Grid>
            </Grid>

        </Box>
    );
}
