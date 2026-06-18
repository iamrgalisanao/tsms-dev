export const formatCurrency = (value) => (
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value || 0)
);

export const formatPercent = (value, digits = 2) => `${Number(value || 0).toFixed(digits)}%`;
