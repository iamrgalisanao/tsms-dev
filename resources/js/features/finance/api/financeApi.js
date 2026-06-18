import axios from 'axios';

export const financePeriodParams = (days) => {
    const today = new Date();
    const start = new Date();
    start.setDate(today.getDate() - (Number.parseInt(days, 10) - 1));

    return {
        start_date: start.toISOString().split('T')[0],
        end_date: today.toISOString().split('T')[0],
    };
};

export const fetchFinanceDashboard = async (days) => {
    const params = financePeriodParams(days);

    const [metricsResponse, chartsResponse] = await Promise.all([
        axios.get('/api/dashboard/metrics', { params }),
        axios.get('/api/dashboard/charts', { params: { days } }),
    ]);

    return {
        metrics: metricsResponse.data,
        charts: chartsResponse.data,
    };
};
