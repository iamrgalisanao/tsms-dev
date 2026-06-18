import { useQuery } from '@tanstack/react-query';
import { fetchFinanceDashboard } from '../api/financeApi';

export const useFinanceDashboard = (dateRange) => {
    return useQuery({
        queryKey: ['finance-dashboard', dateRange],
        queryFn: () => fetchFinanceDashboard(dateRange),
        keepPreviousData: true,
    });
};
