import React from 'react';
import { Box } from '@mui/material';
import { useFinanceDashboard } from '../../features/finance/hooks/useFinanceDashboard';
import FinanceLoadingSkeleton from '../../features/finance/components/FinanceLoadingSkeleton';
import FinanceDashboardHeader from '../../features/finance/components/FinanceDashboardHeader';
import { FinanceKpiGrid, FinanceLeakageGrid } from '../../features/finance/components/FinanceKpiGrids';
import FinanceAlerts from '../../features/finance/components/FinanceAlerts';
import FinanceActivityHeatmap from '../../features/finance/components/FinanceActivityHeatmap';
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

            {/* ── Row 5: Activity Heatmap (full width) ────────────────── */}
            <FinanceActivityHeatmap charts={charts} dateRange={dateRange} />

            {/* ── Bottom analytics: 2 cards per row (6 / 6 columns) ──── */}
            <Box
                sx={{
                    display: 'grid',
                    width: '100%',
                    gridTemplateColumns: { xs: '1fr', lg: 'repeat(2, minmax(0, 1fr))' },
                    gap: 3,
                    mb: 3,
                    alignItems: 'stretch',
                }}
            >
                <Box sx={{ minWidth: 0, display: 'flex' }}>
                    <RevenueTrendCard charts={charts} />
                </Box>
                <Box sx={{ minWidth: 0, display: 'flex' }}>
                    <RevenueCompositionCard data={metrics?.revenue_composition} />
                </Box>
                <Box sx={{ minWidth: 0, display: 'flex' }}>
                    <TopTenantsCard metrics={metrics} />
                </Box>
                <Box sx={{ minWidth: 0, display: 'flex' }}>
                    <QuickActionsHub />
                </Box>
            </Box>

        </Box>
    );
}
