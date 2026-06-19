import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchFinanceMetrics, fetchFinanceCharts } from '../api/financeApi';

/**
 * Returns the ISO date strings for a trailing-N-day window ending today.
 */
const buildDateRange = (days) => {
    const today = new Date();
    const start = new Date();
    start.setDate(today.getDate() - (parseInt(days) - 1));
    return {
        startDate: start.toISOString().split('T')[0],
        endDate: today.toISOString().split('T')[0],
    };
};

/**
 * Primary data hook for the Finance Command Center.
 * Manages the period selector state and returns all dashboard data via TanStack Query.
 */
export function useFinanceDashboard() {
    const [dateRange, setDateRange] = useState('7');

    const { startDate, endDate } = useMemo(() => buildDateRange(dateRange), [dateRange]);

    const metricsQuery = useQuery({
        queryKey: ['finance-metrics', startDate, endDate],
        queryFn: () => fetchFinanceMetrics({ startDate, endDate }),
    });

    const chartsQuery = useQuery({
        queryKey: ['finance-charts', dateRange],
        queryFn: () => fetchFinanceCharts({ days: dateRange }),
    });

    const refetch = () => {
        metricsQuery.refetch();
        chartsQuery.refetch();
    };

    return {
        metrics: metricsQuery.data ?? null,
        charts: chartsQuery.data ?? null,
        loading: metricsQuery.isLoading || chartsQuery.isLoading,
        refreshing: metricsQuery.isFetching || chartsQuery.isFetching,
        isError: metricsQuery.isError || chartsQuery.isError,
        dateRange,
        setDateRange,
        refetch,
    };
}
